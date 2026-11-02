<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp_write\Kernel;

use Drupal\Core\Session\UserSession;
use Drupal\divengine_mcp_write\Entity\McpContentCapability;
use Drupal\divengine_process\Entity\Development;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\divengine_mcp\Kernel\McpTransportTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Administrator-configured content capabilities through /mcp/post (#86).
 */
#[RunTestsInSeparateProcesses]
class McpContentCapabilityTest extends KernelTestBase {

  use McpTransportTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user'];

  /**
   * The mcp module has no schema for its plugins' settings.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  protected Development $alpha;

  protected Development $beta;

  protected Development $gamma;

  protected UserInterface $writer;

  protected UserInterface $colleague;

  /**
   * What the person may do in Studio with articles and briefs.
   */
  const PERSON = [
    'access content',
    'use unified process',
    'create knowledge_article content',
    'edit own knowledge_article content',
    'view own unpublished content',
    'create brief content',
    'edit own brief content',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->setUpMcpSite(['divengine_process', 'divengine_external', 'dblog', 'node', 'text', 'path', 'language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    // The articles use the body storage the Studio base recipe ships.
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    $this->container->get('module_installer')->install(['divengine_articles']);
    NodeType::create(['type' => 'brief', 'name' => 'Brief'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_notes', 'type' => 'string_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'brief', 'field_name' => 'field_notes', 'label' => 'Notes'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_attachment', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'brief', 'field_name' => 'field_attachment', 'label' => 'Attachment'])->save();
    node_access_rebuild();

    // The scope roles: an administrator decision per site (ADR-0020).
    $editor = Role::load('mcp_editor');
    foreach (static::PERSON as $permission) {
      $editor->grantPermission($permission);
    }
    $editor->save();
    Role::load('mcp_agent')->grantPermission('view own unpublished content')->save();

    // User 1.
    $this->createUser([], 'admin');
    $this->writer = $this->person('writer', ['mcp_user', 'mcp_writer']);
    $this->colleague = $this->person('colleague', ['mcp_user']);
    $this->alpha = Development::create(['name' => 'Alpha', 'members' => [$this->writer->id(), $this->colleague->id()]]);
    $this->alpha->save();
    $this->beta = Development::create(['name' => 'Beta secret']);
    $this->beta->save();
    $this->gamma = Development::create(['name' => 'Gamma', 'members' => [$this->writer->id()]]);
    $this->gamma->save();
  }

  protected function person(string $name, array $roles): UserInterface {
    $person = $this->createUser(static::PERSON, $name);
    foreach ($roles as $role) {
      $person->addRole($role);
    }
    $person->save();
    return $person;
  }

  protected function code(Development $development): string {
    return (string) Development::load($development->id())->get('code')->value;
  }

  protected function capability(string $id, array $operations, array $fields = [], string $development = '', bool $require = FALSE): McpContentCapability {
    [$entity_type, $bundle] = explode('--', $id);
    $capability = McpContentCapability::load($id) ?: McpContentCapability::create(['id' => $id, 'label' => $bundle, 'entity_type' => $entity_type, 'bundle' => $bundle]);
    $capability->set('operations', $operations)->set('fields', $fields)->set('development_field', $development)->set('require_development', $require)->save();
    return $capability;
  }

  protected function articles(array $operations): McpContentCapability {
    return $this->capability('node--knowledge_article', $operations, ['title', 'body', 'kb_parent'], 'kb_development', TRUE);
  }

  protected function tool(string $name, array $arguments, string $bearer): array|string {
    $text = $this->callTool($name, $arguments, $bearer);
    $data = json_decode($text, TRUE);
    return is_array($data) ? $data : $text;
  }

  protected function node(string $uuid): ?NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $nodes = $storage->loadByProperties(['uuid' => $uuid]);
    return reset($nodes) ?: NULL;
  }

  public function testModelsFailClosed(): void {
    $models = $this->container->get('divengine_mcp_write.content_models');
    $this->assertNull($models->bundleProblem('node', 'knowledge_article'));
    foreach (['user', 'file', 'artifact', 'trace_link', 'development', 'external_reference'] as $type) {
      $this->assertNotNull($models->entityTypeProblem($type), "$type is refused.");
    }
    $candidates = array_keys($models->writableCandidates('node', 'knowledge_article'));
    foreach (['title', 'body', 'kb_parent'] as $field) {
      $this->assertContains($field, $candidates);
    }
    foreach (['nid', 'uuid', 'vid', 'type', 'uid', 'status', 'langcode', 'created', 'changed', 'promote', 'sticky', 'revision_log', 'revision_uid', 'kb_development'] as $field) {
      $this->assertNotContains($field, $candidates, "$field is never writable.");
    }
    $this->assertNotContains('field_attachment', array_keys($models->writableCandidates('node', 'brief')), 'File fields are not supported.');

    $bad = McpContentCapability::create(['id' => 'node--knowledge_article', 'label' => 'x', 'entity_type' => 'node', 'bundle' => 'knowledge_article', 'operations' => ['create'], 'fields' => ['uid', 'status', 'kb_development', 'nope'], 'development_field' => 'body', 'require_development' => TRUE]);
    $problems = implode("\n", $models->capabilityProblems($bad));
    foreach (['Field uid cannot be writable', 'Field status cannot be writable', 'Field kb_development cannot be writable', 'Field nope does not exist', 'not a single reference to a Development', 'Writes need read'] as $expected) {
      $this->assertStringContainsString($expected, $problems);
    }
    $this->assertNotEmpty($models->capabilityProblems(McpContentCapability::create(['id' => 'user--user', 'label' => 'People', 'entity_type' => 'user', 'bundle' => 'user', 'operations' => ['read']])));

    // A moderated bundle is refused: its state changes through transitions.
    $this->container->get('module_installer')->install(['content_moderation']);
    $workflow = Workflow::create(['id' => 'briefs', 'label' => 'Briefs', 'type' => 'content_moderation']);
    $workflow->getTypePlugin()->addEntityTypeAndBundle('node', 'brief');
    $workflow->save();
    $this->assertStringContainsString('Moderated bundles are not supported', (string) $this->container->get('divengine_mcp_write.content_models')->bundleProblem('node', 'brief'));
  }

  public function testNothingIsExposedUntilConfigured(): void {
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->assertSame([], array_values(preg_grep('/^records/', $this->toolNames($bearer))));
    // A capability with nothing enabled exposes nothing either.
    $this->capability('node--knowledge_article', []);
    $this->assertSame([], array_values(preg_grep('/^records/', $this->toolNames($bearer))));
    $this->assertContains('engineering_create_artifact', $this->toolNames($bearer), 'Engineering writes are unaffected.');
  }

  public function testKnowledgeArticleThroughMcp(): void {
    $this->articles(['read', 'create', 'update']);
    $alpha = $this->code($this->alpha);
    $writer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $writer_read = 'Bearer ' . $this->oauthToken($this->writer, ['mcp']);
    $colleague = 'Bearer ' . $this->oauthToken($this->colleague, ['mcp']);

    // Discovery: read for read-only tokens, writes only for the writer.
    $this->assertSame(['records_capabilities', 'records_get', 'records_list'], $this->sorted(preg_grep('/^records/', $this->toolNames($writer_read))));
    $this->assertSame($this->sorted(['records_capabilities', 'records_get', 'records_list', 'records-write_create', 'records-write_update']), $this->sorted(preg_grep('/^records/', $this->toolNames($writer))));
    $capabilities = $this->tool('records_capabilities', [], $writer);
    $this->assertSame('node--knowledge_article', $capabilities[0]['type']);
    $this->assertSame(['read', 'create', 'update'], $capabilities[0]['operations']);
    $this->assertSame(['read', 'create', 'update'], $capabilities[0]['you_may']);
    $this->assertSame(['read'], $this->tool('records_capabilities', [], $writer_read)[0]['you_may']);
    $this->assertSame('required on create', $capabilities[0]['development']);

    // A Development is required, must be visible, and the person must be
    // able to add records to it; protected fields cannot be set.
    $base = ['type' => 'node--knowledge_article', 'values' => ['title' => 'Contexto de Áncora', 'body' => ['value' => 'Lluvia de ideas.', 'format' => 'plain_text']], 'langcode' => 'es', 'log' => 'Contexto inicial'];
    $this->assertSame('Error: node--knowledge_article records need a Development: pass its code.', $this->tool('records-write_create', $base, $writer));
    $this->assertSame('Error: No Development with code ' . $this->code($this->beta) . ' is visible to you.', $this->tool('records-write_create', $base + ['development' => $this->code($this->beta)], $writer));
    foreach (['uid' => $this->colleague->id(), 'status' => TRUE, 'kb_development' => $this->beta->id(), 'promote' => TRUE] as $field => $value) {
      $answer = $this->tool('records-write_create', ['values' => $base['values'] + [$field => $value], 'development' => $alpha] + $base, $writer);
      $this->assertStringContainsString("These fields cannot be set: $field", (string) $answer);
    }
    $this->assertStringContainsString('You may not use text format', (string) $this->tool('records-write_create', ['values' => ['title' => 'x', 'body' => ['value' => 'x', 'format' => 'full_html']], 'development' => $alpha] + $base, $writer));
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute(), 'Nothing was written.');

    // Create: unpublished, owned by the person, in the Development, Spanish.
    $created = $this->tool('records-write_create', $base + ['development' => $alpha, 'idempotency_key' => 'ancora-1'], $writer);
    $this->assertIsArray($created, (string) json_encode($created));
    $this->assertFalse($created['published']);
    $this->assertSame('es', $created['langcode']);
    $this->assertSame($alpha, $created['development']);
    $this->assertSame('Contexto de Áncora', $created['label']);
    $node = $this->node($created['id']);
    $this->assertSame((string) $this->writer->id(), (string) $node->getOwnerId());
    $this->assertSame((string) $this->writer->id(), (string) $node->getRevisionUserId());
    $this->assertFalse($node->isPublished());
    $this->assertSame('Through MCP (Test client): Contexto inicial', $node->getRevisionLogMessage());
    $again = $this->tool('records-write_create', $base + ['development' => $alpha, 'idempotency_key' => 'ancora-1'], $writer);
    $this->assertTrue($again['replayed']);
    $this->assertSame($created['id'], $again['id']);
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute());

    // The author reads the private draft back, with a read-only token too;
    // a colleague of the Development does not see it, nor learns it exists.
    $this->assertSame($created['id'], $this->tool('records_get', ['type' => 'node--knowledge_article', 'id' => $created['id']], $writer_read)['id']);
    $this->assertSame([$created['id']], array_column($this->tool('records_list', ['type' => 'node--knowledge_article', 'development' => $alpha], $writer)['records'], 'id'));
    $hidden = $this->tool('records_get', ['type' => 'node--knowledge_article', 'id' => $created['id']], $colleague);
    $this->assertSame("Error: No node--knowledge_article record with id {$created['id']} is visible to you.", $hidden);
    $this->assertSame($hidden, str_replace('00000000-0000-4000-8000-000000000000', $created['id'], (string) $this->tool('records_get', ['type' => 'node--knowledge_article', 'id' => '00000000-0000-4000-8000-000000000000'], $colleague)));
    $this->assertSame([], $this->tool('records_list', ['type' => 'node--knowledge_article'], $colleague)['records']);

    // Revise: a new revision with the log; stale and protected writes fail.
    $revised = $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['title' => 'Contexto de Áncora GOH'], 'log' => 'Título', 'base_revision' => $created['revision']], $writer);
    $this->assertIsArray($revised, (string) json_encode($revised));
    $this->assertGreaterThan($created['revision'], $revised['revision']);
    $this->assertFalse($revised['published'], 'A revision does not publish.');
    $this->assertStringContainsString('changed after revision', (string) $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['title' => 'x'], 'log' => 'x', 'base_revision' => $created['revision']], $writer));
    $this->assertStringContainsString('These fields cannot be set: kb_development', (string) $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['kb_development' => $this->gamma->id()], 'log' => 'x'], $writer));
    $this->assertStringContainsString('log', (string) $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['title' => 'x'], 'log' => ''], $writer));

    // A parent in another Development would move the article (the articles
    // module gives sub-articles their parent's Development): refused and
    // rolled back. A hidden parent is not even named.
    $gamma_parent = Node::create(['type' => 'knowledge_article', 'title' => 'Gamma guide', 'uid' => $this->writer->id(), 'status' => 1, 'kb_development' => $this->gamma->id()]);
    $gamma_parent->save();
    $beta_parent = Node::create(['type' => 'knowledge_article', 'title' => 'Beta guide', 'uid' => 1, 'status' => 1, 'kb_development' => $this->beta->id()]);
    $beta_parent->save();
    $revisions = (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {node_revision} WHERE nid = :nid', [':nid' => $node->id()])->fetchField();
    $this->assertStringContainsString('another Development', (string) $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['kb_parent' => $gamma_parent->uuid()], 'log' => 'Mover'], $writer));
    $this->assertSame("Error: Field kb_parent: no node with id {$beta_parent->uuid()} is visible to you.", $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['kb_parent' => $beta_parent->uuid()], 'log' => 'Mover'], $writer));
    $node = $this->node($created['id']);
    $this->assertSame((string) $this->alpha->id(), (string) $node->get('kb_development')->target_id);
    $this->assertTrue($node->get('kb_parent')->isEmpty());
    $this->assertSame($revisions, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {node_revision} WHERE nid = :nid', [':nid' => $node->id()])->fetchField(), 'The refused revision was rolled back.');

    // Turning update off removes the tool at once and refuses the call.
    $this->articles(['read', 'create']);
    $this->assertNotContains('records-write_update', $this->toolNames($writer));
    // The tool no longer exists, so the mcp module refuses the call.
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('records-write_update', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'values' => ['title' => 'x'], 'log' => 'x'], $writer));

    // Delete is off unless enabled; then it needs confirmation and the
    // person's own delete access, which this person does not have.
    $this->assertNotContains('records-write_delete', $this->toolNames($writer));
    $this->articles(['read', 'create', 'update', 'delete']);
    $this->assertContains('records-write_delete', $this->toolNames($writer));
    $this->assertStringContainsString('confirm: true', (string) $this->tool('records-write_delete', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'confirm' => FALSE], $writer));
    $this->assertSame('Error: You may not delete this record.', $this->tool('records-write_delete', ['type' => 'node--knowledge_article', 'id' => $created['id'], 'confirm' => TRUE], $writer));
    $this->assertNotNull($this->node($created['id']));

    // The audit log names the operation, person and client, not content.
    $log = $this->container->get('database')->select('watchdog', 'w')->fields('w', ['message', 'variables'])->condition('type', 'divengine_mcp_write')->execute()->fetchAll();
    $entries = implode("\n", array_map(fn ($row) => strtr($row->message, unserialize($row->variables)), $log));
    $this->assertStringContainsString(sprintf('content create node knowledge_article %s by user %s through MCP client Test client.', $node->id(), $this->writer->id()), $entries);
    $this->assertStringNotContainsString('Lluvia', $entries);
  }

  public function testSecondBundleByConfigurationOnly(): void {
    $writer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->capability('node--brief', ['read', 'create'], ['title', 'field_notes']);
    $created = $this->tool('records-write_create', ['type' => 'node--brief', 'values' => ['title' => 'Brief', 'field_notes' => 'Notes'], 'log' => 'New'], $writer);
    $this->assertIsArray($created, (string) json_encode($created));
    $this->assertSame('Notes', $created['fields']['field_notes']);
    $this->assertArrayNotHasKey('development', $created);
    $this->assertStringContainsString('have no Development', (string) $this->tool('records-write_create', ['type' => 'node--brief', 'values' => ['title' => 'x'], 'development' => $this->code($this->alpha)], $writer));
    $this->assertStringContainsString('These fields cannot be set: field_attachment', (string) $this->tool('records-write_create', ['type' => 'node--brief', 'values' => ['title' => 'x', 'field_attachment' => 1]], $writer));

    // A capability for an unsupported type, saved bypassing the form, is
    // refused, not used: it appears nowhere.
    McpContentCapability::create(['id' => 'user--user', 'label' => 'People', 'entity_type' => 'user', 'bundle' => 'user', 'operations' => ['read', 'update'], 'fields' => ['name']])->save();
    $this->assertSame(['node--brief'], array_column($this->tool('records_capabilities', [], $writer), 'type'));
    $this->assertStringContainsString('not enabled for user--user', (string) $this->tool('records_get', ['type' => 'user--user', 'id' => User::load(1)->uuid()], $writer));
  }

  public function testWritesKeepTheWriterGates(): void {
    $this->articles(['read', 'create', 'update']);
    $create = ['type' => 'node--knowledge_article', 'values' => ['title' => 'x'], 'development' => $this->code($this->alpha)];
    $no_writes = fn (string $bearer) => array_values(preg_grep('/^records-write/', $this->toolNames($bearer)));

    // Read-only token of the writer: reads, no writes, direct call refused.
    $read_only = 'Bearer ' . $this->oauthToken($this->writer, ['mcp']);
    $this->assertSame([], $no_writes($read_only));
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('records-write_create', $create, $read_only));
    // The shared service token.
    $bot = $this->createUser([], 'mcp_bot');
    $bot->addRole('mcp_agent');
    $bot->save();
    $this->grantMcpToken($bot);
    $this->assertContains('records_list', $this->toolNames($this->basic()));
    $this->assertSame([], $no_writes($this->basic()));
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('records-write_create', $create, $this->basic()));
    // Write scope without the MCP writer role, and user 1 without it.
    $this->assertSame([], $no_writes('Bearer ' . $this->oauthToken($this->colleague, ['mcp', 'mcp_write'])));
    $this->assertSame([], $no_writes('Bearer ' . $this->oauthToken(User::load(1), ['mcp', 'mcp_write'])));
    // Browser sessions.
    $this->setCurrentUser($this->writer);
    $this->assertFalse($this->container->get('plugin.manager.mcp')->createInstance('records-write')->hasAccess()->isAllowed());

    // A revoked role stops the writer at once.
    $writer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->assertContains('records-write_create', $this->toolNames($writer));
    $permissions = Role::load('mcp_writer')->getPermissions();
    Role::load('mcp_writer')->delete();
    $this->assertSame([], $no_writes($writer));
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute(), 'Nothing was written.');
    $this->assertNotEmpty($permissions);

    // The scope role decides too: without bundle permissions there, a
    // token cannot create even for a person who could in Studio.
    $session = new UserSession(['uid' => 0, 'roles' => ['authenticated', 'mcp_editor']]);
    $this->assertTrue($this->container->get('entity_type.manager')->getAccessControlHandler('node')->createAccess('knowledge_article', $session));
  }

  /**
   * @return string[]
   */
  protected function sorted(array $values): array {
    $values = array_values($values);
    sort($values);
    return $values;
  }

}
