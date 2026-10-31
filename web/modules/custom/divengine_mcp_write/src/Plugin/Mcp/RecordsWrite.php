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
 * Writes to the content bundles administrators enabled for MCP (#86).
 *
 * The same gates as engineering writes (ADR-0019, #79): a person's OAuth
 * token with the mcp_write scope, of an MCP writer. Each tool is listed only
 * when some capability enables its operation, and its "type" enum holds only
 * those bundles; turning an operation off removes it at once.
 */
#[Mcp(
  id: 'records-write',
  name: new TranslatableMarkup('Divengine configured content: write'),
  description: new TranslatableMarkup('Creates, revises and, where enabled, deletes records of the content bundles an administrator enabled for MCP.'),
)]
class RecordsWrite extends McpPluginBase {

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
    return parent::hasAccess()->andIf($this->writerAccess())->setCacheMaxAge(0);
  }

  public function getTools(): array {
    $tools = [];
    $type = fn (string $operation) => ['type' => 'string', 'enum' => array_keys($this->gateway->capabilities($operation)), 'description' => 'The content type, as listed by records_capabilities.'];
    $values = ['type' => 'object', 'description' => 'Field values keyed by field name; only the writable fields listed by records_capabilities. Formatted text is a string or {value, format}; references are record ids (UUIDs).'];
    $log = ['type' => 'string', 'description' => 'Why; kept in the revision history.'];
    if ($this->gateway->capabilities('create')) {
      $tools[] = new Tool(
        name: 'create',
        description: 'Creates a record, unpublished and owned by you. Pass the Development code where the type has one. Pass an idempotency_key to retry safely.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'type' => $type('create'),
            'values' => $values,
            'development' => ['type' => 'string', 'description' => 'Development code, e.g. DEV-0001.'],
            'langcode' => ['type' => 'string', 'description' => 'Language of the record, e.g. es.'],
            'log' => $log,
            'idempotency_key' => ['type' => 'string', 'maxLength' => 64],
          ],
          'required' => ['type', 'values'],
        ],
        title: 'Create record',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: FALSE, openWorldHint: FALSE),
      );
    }
    if ($this->gateway->capabilities('update')) {
      $tools[] = new Tool(
        name: 'update',
        description: 'Revises a record: a new revision with your log. Publication, owner, Development and language do not change. Pass base_revision (from records_get) to refuse if it changed meanwhile.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'type' => $type('update'),
            'id' => ['type' => 'string', 'description' => 'The record id (UUID).'],
            'values' => $values,
            'log' => $log,
            'base_revision' => ['type' => 'integer'],
          ],
          'required' => ['type', 'id', 'values', 'log'],
        ],
        title: 'Revise record',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: FALSE, openWorldHint: FALSE),
      );
    }
    if ($this->gateway->capabilities('delete')) {
      $tools[] = new Tool(
        name: 'delete',
        description: 'Deletes a record. Destructive: ask the person first and pass confirm: true.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'type' => $type('delete'),
            'id' => ['type' => 'string'],
            'confirm' => ['type' => 'boolean', 'description' => 'true only after the person confirmed.'],
          ],
          'required' => ['type', 'id', 'confirm'],
        ],
        title: 'Delete record',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: TRUE, openWorldHint: FALSE),
      );
    }
    return $tools;
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    if (!$this->hasAccess()->isAllowed()) {
      return $this->answer('Error: writes are not available to this connection.', TRUE);
    }
    $arguments = (array) $arguments;
    /** @var \Drupal\simple_oauth\Authentication\TokenAuthUserInterface $account */
    $account = $this->currentUser->getAccount();
    $client = $account->getConsumer()->label() ?: 'unknown client';
    $string = fn (string $key) => is_scalar($arguments[$key] ?? NULL) ? (string) $arguments[$key] : '';
    $values = json_decode(json_encode($arguments['values'] ?? []), TRUE);
    if (!is_array($values)) {
      return $this->answer('Error: values must be an object.', TRUE);
    }
    try {
      $result = match ($toolId) {
        'create', md5('create') => $this->gateway->create($string('type'), $values, $account, $client, $string('development'), $string('langcode'), $string('log'), mb_substr($string('idempotency_key'), 0, 64)),
        'update', md5('update') => $this->gateway->update($string('type'), $string('id'), $values, $account, $client, $string('log'), isset($arguments['base_revision']) && is_numeric($arguments['base_revision']) ? (int) $arguments['base_revision'] : NULL),
        'delete', md5('delete') => $this->gateway->delete($string('type'), $string('id'), ($arguments['confirm'] ?? FALSE) === TRUE, $account, $client),
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
