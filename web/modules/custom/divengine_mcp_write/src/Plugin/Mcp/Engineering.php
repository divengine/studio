<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp_write\Plugin\Mcp;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_mcp_oauth\Controller\OAuthMetadataController;
use Drupal\divengine_mcp_write\EngineeringWriteException;
use Drupal\divengine_mcp_write\EngineeringWriter;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\mcp\ServerFeatures\ToolAnnotations;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Engineering writes for the person a client acts for (ADR-0019).
 *
 * Available only to OAuth tokens that carry the mcp_write scope, of people
 * with "Write engineering records through MCP". The shared service token,
 * read-only tokens and browser sessions never see these tools. Every tool
 * goes through EngineeringWriter: an enumerated operation with the person's
 * access, validation, revisions and an audit log entry.
 */
#[Mcp(
  id: 'engineering',
  name: new TranslatableMarkup('Divengine engineering writes'),
  description: new TranslatableMarkup('Lets the AI client of an authorized person create and revise requirements, decisions and evidence, register external work and link them.'),
)]
class Engineering extends McpPluginBase {

  /**
   * The role a person must hold, explicitly, for their clients to write.
   */
  const WRITER_ROLE = 'mcp_writer';

  protected EngineeringWriter $writer;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->writer = $container->get('divengine_mcp_write.writer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Only a person's OAuth token with the write scope, of an MCP writer.
   *
   * Permissions are not enough on their own: user 1 and administrator roles
   * have every permission, whatever the scope, and a token's roles are those
   * of its scopes. So the scope is read from the token, and the MCP writer
   * role from the person's account as stored now (#79). The answer is never
   * cached: removing the role or the token takes effect on the next request.
   */
  public function hasAccess(): AccessResult {
    $account = $this->currentUser->getAccount();
    if (!$account instanceof TokenAuthUserInterface) {
      return AccessResult::forbidden('Engineering writes need an OAuth token.')->setCacheMaxAge(0);
    }
    $scopes = array_column($account->getToken()->get('scopes')->getValue(), 'scope_id');
    if (!in_array(OAuthMetadataController::WRITE_SCOPE, $scopes, TRUE)) {
      return AccessResult::forbidden('The token does not have the mcp_write scope.')->setCacheMaxAge(0);
    }
    /** @var \Drupal\user\UserInterface|null $person */
    $person = $this->entityTypeManager->getStorage('user')->loadUnchanged($account->id());
    if (!$person || !$person->isActive() || !in_array(static::WRITER_ROLE, $person->getRoles(TRUE), TRUE)) {
      return AccessResult::forbidden('The person does not have the MCP writer role.')->setCacheMaxAge(0);
    }
    return parent::hasAccess()
      ->andIf(AccessResult::allowedIfHasPermission($account, 'write engineering records through mcp'))
      ->setCacheMaxAge(0);
  }

  public function getTools(): array {
    $development = ['type' => 'string', 'description' => 'Development code, e.g. DEV-0001.'];
    $state = ['type' => 'string', 'enum' => EngineeringWriter::STATES, 'description' => 'draft (default); review (decisions, evidence) or proposed (requirements) to submit it. Approving is done by people in Studio.'];
    $text = [
      'title' => ['type' => 'string', 'maxLength' => 255],
      'summary' => ['type' => 'string', 'description' => 'One or two sentences.'],
      'body' => ['type' => 'string', 'description' => 'The full text, stored as plain text.'],
    ];
    // Requirements only; the list values are those of the Studio form.
    $text += [
      'statement' => ['type' => 'string', 'description' => 'Requirements: what the system shall do. Required to create one.'],
      'rationale' => ['type' => 'string', 'description' => 'Requirements: why it is needed.'],
      'source' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Requirements: where it comes from.'],
      'acceptance_notes' => ['type' => 'string', 'description' => 'Requirements: how it will be accepted.'],
      'requirement_type' => ['type' => 'string', 'enum' => $this->allowed('field_requirement_type'), 'description' => 'Requirements; default functional.'],
      'priority' => ['type' => 'string', 'enum' => $this->allowed('field_priority'), 'description' => 'Requirements; default unassessed.'],
      'risk' => ['type' => 'string', 'enum' => $this->allowed('field_risk'), 'description' => 'Requirements; default unassessed.'],
    ];
    $code = ['type' => 'string', 'description' => 'Record code: REQ-0001, DEC-0001, EVD-0001 or EXT-0001.'];
    return [
      new Tool(
        name: 'developments',
        description: 'Lists the Developments you can see and whether you can add records to them. Start here.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
        title: 'Developments',
        annotations: new ToolAnnotations(title: 'Developments', readOnlyHint: TRUE, idempotentHint: TRUE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'read',
        description: 'Reads a requirement, decision, evidence or external reference by code: its latest revision, state, and trace links. Use it to check what you wrote.',
        inputSchema: ['type' => 'object', 'properties' => ['code' => $code], 'required' => ['code']],
        title: 'Read record',
        annotations: new ToolAnnotations(title: 'Read record', readOnlyHint: TRUE, idempotentHint: TRUE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'create_artifact',
        description: 'Creates a requirement, decision or evidence in a Development, as a draft (or submitted). Pass an idempotency_key to retry safely.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'development' => $development,
            'type' => ['type' => 'string', 'enum' => EngineeringWriter::TYPES],
            'state' => $state,
            'log' => ['type' => 'string', 'description' => 'Why it is created; kept in the revision history.'],
            'idempotency_key' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Any unique string; repeating it returns the record it created instead of a duplicate.'],
          ] + $text,
          'required' => ['development', 'type', 'title'],
        ],
        title: 'Create requirement, decision or evidence',
        annotations: new ToolAnnotations(title: 'Create artifact', readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'update_artifact',
        description: 'Revises a requirement, decision or evidence: a new revision, as a draft unless submitted. A baselined requirement keeps its baselined revision until people approve the new one; an approved one goes back to draft and needs approval again. Pass base_revision (from read) to refuse if someone changed it meanwhile.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'code' => $code,
            'state' => $state,
            'log' => ['type' => 'string', 'description' => 'Why it changes; required.'],
            'base_revision' => ['type' => 'integer', 'description' => 'The revision you read.'],
          ] + $text,
          'required' => ['code', 'log'],
        ],
        title: 'Revise artifact',
        annotations: new ToolAnnotations(title: 'Revise artifact', readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'register_external',
        description: 'Registers external work (a GitHub issue, pull request, commit, Actions run, repository, or any web page) in a Development, or returns the reference already registered. Studio stores the link, not the work.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'development' => $development,
            'url' => ['type' => 'string', 'description' => 'e.g. https://github.com/owner/repository/pull/12'],
            'label' => ['type' => 'string', 'maxLength' => 255],
            'notes' => ['type' => 'string'],
          ],
          'required' => ['development', 'url'],
        ],
        title: 'Register external work',
        annotations: new ToolAnnotations(title: 'Register external work', readOnlyHint: FALSE, idempotentHint: TRUE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'link',
        description: 'Links two records of the same Development with a typed trace link, read "source type target": EXT-0003 implements REQ-0001, EXT-0004 verifies REQ-0001, DEC-0002 satisfies REQ-0001, REQ-0001 references EXT-0005. An existing identical link is returned.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'source' => $code,
            'type' => ['type' => 'string', 'description' => 'implements, verifies, references, satisfies, refines, derives_from, depends_on, documents…'],
            'target' => $code,
            'rationale' => ['type' => 'string'],
          ],
          'required' => ['source', 'type', 'target'],
        ],
        title: 'Link records',
        annotations: new ToolAnnotations(title: 'Link records', readOnlyHint: FALSE, idempotentHint: TRUE, destructiveHint: FALSE, openWorldHint: FALSE),
      ),
      new Tool(
        name: 'unlink',
        description: 'Removes a trace link by its number (from read). Destructive: ask the person first and pass confirm: true.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'trace_link' => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean', 'description' => 'true only after the person confirmed.'],
          ],
          'required' => ['trace_link', 'confirm'],
        ],
        title: 'Remove trace link',
        annotations: new ToolAnnotations(title: 'Remove trace link', readOnlyHint: FALSE, idempotentHint: FALSE, destructiveHint: TRUE, openWorldHint: FALSE),
      ),
    ];
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    // Checked again here: executeTool() must never run for a token that
    // lost the scope or the permission.
    if (!$this->hasAccess()->isAllowed()) {
      return $this->answer('Error: engineering writes are not available to this connection.', TRUE);
    }
    $arguments = (array) $arguments;
    $account = $this->currentUser->getAccount();
    /** @var \Drupal\simple_oauth\Authentication\TokenAuthUserInterface $account */
    $client = $account->getConsumer()->label() ?: 'unknown client';
    $string = fn (string $key) => is_scalar($arguments[$key] ?? NULL) ? (string) $arguments[$key] : '';
    $values = array_filter(array_intersect_key($arguments, array_flip(EngineeringWriter::arguments())), 'is_string');
    try {
      $result = match ($toolId) {
        'developments', md5('developments') => $this->writer->developments($account),
        'read', md5('read') => $this->writer->read($string('code'), $account),
        'create_artifact', md5('create_artifact') => $this->writer->createArtifact($string('development'), $string('type'), $values, $account, $client, mb_substr($string('idempotency_key'), 0, 64)),
        'update_artifact', md5('update_artifact') => $this->writer->updateArtifact($string('code'), $values, $account, $client, isset($arguments['base_revision']) && is_numeric($arguments['base_revision']) ? (int) $arguments['base_revision'] : NULL),
        'register_external', md5('register_external') => $this->writer->registerExternal($string('development'), $string('url'), $account, $client, $string('label'), $string('notes')),
        'link', md5('link') => $this->writer->link($string('source'), $string('type'), $string('target'), $account, $client, $string('rationale')),
        'unlink', md5('unlink') => $this->writer->unlink((int) $string('trace_link'), ($arguments['confirm'] ?? FALSE) === TRUE, $account, $client),
        default => throw new EngineeringWriteException('Unknown tool.'),
      };
    }
    catch (EngineeringWriteException $e) {
      return $this->answer('Error: ' . $e->getMessage(), TRUE);
    }
    return $this->answer(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * The values a requirement list field allows.
   *
   * @return string[]
   */
  protected function allowed(string $field): array {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('artifact', 'requirement');
    return isset($definitions[$field]) ? array_map('strval', array_keys($definitions[$field]->getSetting('allowed_values') ?? [])) : [];
  }

  protected function answer(string $text, bool $error = FALSE): array {
    return $error ? ['content' => [['type' => 'text', 'text' => $text]], 'isError' => TRUE] : [['type' => 'text', 'text' => $text]];
  }

}
