<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write\Plugin\Mcp;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_mcp_write\ContentGateway;
use Drupal\divengine_mcp_write\EngineeringWriteException;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\mcp\ServerFeatures\ToolAnnotations;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads of the content bundles administrators enabled for MCP (#86).
 *
 * Read needs no write scope or role (ADR-0020): every record is read as the
 * person, through Drupal's view access, entity queries with node grants and
 * field access. Only bundles with an enabled "read" capability are listed.
 */
#[Mcp(
  id: 'records',
  name: new TranslatableMarkup('Divengine configured content: read'),
  description: new TranslatableMarkup('Lists and reads the records of the content bundles an administrator enabled for MCP.'),
)]
class Records extends McpPluginBase {

  use McpWriterAccessTrait;

  protected ContentGateway $gateway;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->gateway = $container->get('divengine_mcp_write.content');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function hasAccess(): AccessResult {
    return parent::hasAccess()->setCacheMaxAge(0);
  }

  public function getTools(): array {
    $types = array_keys($this->gateway->capabilities('read'));
    if (!$types) {
      return [];
    }
    $type = ['type' => 'string', 'enum' => $types, 'description' => 'The content type, as listed by records_capabilities.'];
    $read_only = new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE, destructiveHint: FALSE, openWorldHint: FALSE);
    return [
      new Tool(
        name: 'capabilities',
        description: 'Lists the content types you can work with through MCP, their operations, writable fields and Development rules. Start here.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
        title: 'Content capabilities',
        annotations: $read_only,
      ),
      new Tool(
        name: 'list',
        description: 'Lists records of a content type that you can see, newest first, a page at a time (at most 50).',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'type' => $type,
            'development' => ['type' => 'string', 'description' => 'Only records of this Development (code, e.g. DEV-0001).'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ContentGateway::MAX_LIMIT],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ContentGateway::MAX_OFFSET],
          ],
          'required' => ['type'],
        ],
        title: 'List records',
        annotations: $read_only,
      ),
      new Tool(
        name: 'get',
        description: 'Reads a record by id: its latest revision and the fields you can see.',
        inputSchema: ['type' => 'object', 'properties' => ['type' => $type, 'id' => ['type' => 'string', 'description' => 'The record id (UUID).']], 'required' => ['type', 'id']],
        title: 'Read record',
        annotations: $read_only,
      ),
    ];
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    if (!$this->hasAccess()->isAllowed()) {
      return $this->answer('Error: configured content is not available to this connection.', TRUE);
    }
    $arguments = (array) $arguments;
    $account = $this->currentUser->getAccount();
    $string = fn (string $key) => is_scalar($arguments[$key] ?? NULL) ? (string) $arguments[$key] : '';
    try {
      $result = match ($toolId) {
        'capabilities', md5('capabilities') => $this->gateway->describeCapabilities($account, $this->writerAccess()->isAllowed()),
        'list', md5('list') => $this->gateway->list($string('type'), $account, (int) ($arguments['limit'] ?? 20), (int) ($arguments['offset'] ?? 0), $string('development')),
        'get', md5('get') => $this->gateway->read($string('type'), $string('id'), $account),
        default => throw new EngineeringWriteException('Unknown tool.'),
      };
    }
    catch (EngineeringWriteException $e) {
      return $this->answer('Error: ' . $e->getMessage(), TRUE);
    }
    return $this->answer(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  protected function answer(string $text, bool $error = FALSE): array {
    return $error ? ['content' => [['type' => 'text', 'text' => $text]], 'isError' => TRUE] : [['type' => 'text', 'text' => $text]];
  }

}
