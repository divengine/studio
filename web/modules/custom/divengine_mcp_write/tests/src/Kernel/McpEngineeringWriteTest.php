<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_mcp_write\Kernel;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Development;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\divengine_mcp\Kernel\McpTransportTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Engineering writes through /mcp/post, with real credentials (#71).
 */
#[RunTestsInSeparateProcesses]
class McpEngineeringWriteTest extends KernelTestBase {

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

  protected UserInterface $writer;

  /**
   * What a content editor may do, as the Studio base recipe grants it.
   */
  const EDITOR = [
    'access divengine',
    'use unified process',
    'view artifact',
    'view artifact revisions',
    'view latest version',
    'create artifact',
    'edit artifact',
    'view trace links',
    'view trace link revisions',
    'create trace links',
    'delete trace links',
    'link external work',
    'use requirement_lifecycle transition draft',
    'use requirement_lifecycle transition propose',
    'use requirement_lifecycle transition approve',
    'use artifact_lifecycle transition create_new_draft',
    'use artifact_lifecycle transition submit_for_review',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->setUpMcpSite(['divengine_process', 'divengine_external', 'dblog']);
    // User 1.
    $this->createUser([], 'admin');
    $this->writer = $this->person(['mcp_user', 'mcp_writer']);
    $this->alpha = Development::create(['name' => 'Alpha', 'members' => [$this->writer->id()]]);
    $this->alpha->save();
    $this->beta = Development::create(['name' => 'Beta secret']);
    $this->beta->save();
  }

  /**
   * A person with the editor's permissions and the given roles.
   */
  protected function person(array $roles): UserInterface {
    $person = $this->createUser(static::EDITOR);
    foreach ($roles as $role) {
      $person->addRole($role);
    }
    $person->save();
    return $person;
  }

  /**
   * Calls an engineering tool; returns the decoded answer, or the error.
   */
  protected function tool(string $name, array $arguments, string $bearer): array|string {
    $text = $this->callTool("engineering_$name", $arguments, $bearer);
    $data = json_decode($text, TRUE);
    return is_array($data) ? $data : $text;
  }

  protected function code(Development $development): string {
    return (string) Development::load($development->id())->get('code')->value;
  }

  public function testWriterTracesARequirementToGitHubAndReadsItBack(): void {
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->assertContains('engineering_create_artifact', $this->toolNames($bearer));
    $this->assertSame([['code' => $this->code($this->alpha), 'name' => 'Alpha', 'writable' => TRUE]], $this->tool('developments', [], $bearer));

    // A requirement needs its statement; the error names the argument.
    $this->assertSame('Error: statement: This value should not be null.', $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'No statement'], $bearer));
    $this->assertStringContainsString('priority', (string) $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'Bad priority', 'statement' => 'x', 'priority' => 'urgent!'], $bearer));
    $this->assertSame('Error: A decision has no statement.', $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'decision', 'title' => 'x', 'statement' => 'x'], $bearer));

    $requirement = $this->tool('create_artifact', [
      'development' => $this->code($this->alpha),
      'type' => 'requirement',
      'title' => 'Legacy behaviour D1–D9 is preserved',
      'statement' => 'The remanufactured system shall preserve the characterized legacy behaviour D1–D9.',
      'source' => 'Áncora characterization report',
      'body' => '<script>alert(1)</script> Characterized behaviour.',
      'log' => 'From the Áncora characterization.',
      'idempotency_key' => 'req-1',
    ], $bearer);
    $this->assertIsArray($requirement, (string) json_encode($requirement));
    $this->assertMatchesRegularExpression('/^REQ-\d{4}$/', $requirement['code']);
    $this->assertSame('draft', $requirement['state']);
    $this->assertSame('The remanufactured system shall preserve the characterized legacy behaviour D1–D9.', $requirement['statement']);
    $this->assertSame('unassessed', $requirement['priority'], 'List fields keep their defaults.');

    $artifact = Artifact::load(current($this->container->get('entity_type.manager')->getStorage('artifact')->getQuery()->accessCheck(FALSE)->condition('artifact_key', $requirement['code'])->execute()));
    $this->assertSame((string) $this->writer->id(), (string) $artifact->getOwnerId());
    $this->assertSame((string) $this->writer->id(), (string) $artifact->getRevisionUserId());
    $this->assertSame('Through MCP (Test client): From the Áncora characterization.', $artifact->getRevisionLogMessage());
    $this->assertSame('plain_text', $artifact->get('body')->format, 'Bodies cannot carry markup.');

    // Retrying with the same key gives the same answer, not a duplicate.
    $again = $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'Legacy behaviour D1–D9 is preserved', 'statement' => 'The remanufactured system shall preserve the characterized legacy behaviour D1–D9.', 'source' => 'Áncora characterization report', 'body' => '<script>alert(1)</script> Characterized behaviour.', 'log' => 'From the Áncora characterization.', 'idempotency_key' => 'req-1'], $bearer);
    $this->assertSame($requirement['code'], $again['code']);
    $this->assertTrue($again['replayed']);
    $this->assertStringContainsString('different request', $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'Other', 'idempotency_key' => 'req-1'], $bearer));
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('artifact')->getQuery()->accessCheck(FALSE)->count()->execute());

    // External work, linked both ways the pilot needs.
    $pr99 = $this->tool('register_external', ['development' => $this->code($this->alpha), 'url' => 'https://github.com/rafageist/ancora-goh/pull/99'], $bearer);
    $this->assertMatchesRegularExpression('/^EXT-\d{4}$/', $pr99['code']);
    $pr98 = $this->tool('register_external', ['development' => $this->code($this->alpha), 'url' => 'https://github.com/rafageist/ancora-goh/pull/98'], $bearer);
    $this->assertSame($pr99['code'], $this->tool('register_external', ['development' => $this->code($this->alpha), 'url' => 'https://github.com/RafaGeist/ancora-goh/pull/99/files'], $bearer)['code']);
    $this->assertTrue($this->tool('link', ['source' => $pr99['code'], 'type' => 'implements', 'target' => $requirement['code']], $bearer)['created']);
    $this->assertTrue($this->tool('link', ['source' => $pr98['code'], 'type' => 'verifies', 'target' => $requirement['code'], 'rationale' => 'Characterization tests'], $bearer)['created']);
    $this->assertFalse($this->tool('link', ['source' => $pr98['code'], 'type' => 'verifies', 'target' => $requirement['code']], $bearer)['created']);

    $read = $this->tool('read', ['code' => $requirement['code']], $bearer);
    $this->assertSame(['implements', 'verifies'], array_column($read['traces'], 'type'));
    $this->assertSame([$pr99['code'], $pr98['code']], array_column($read['traces'], 'source'));

    // A revision based on what was read; a stale one is refused.
    $revised = $this->tool('update_artifact', ['code' => $requirement['code'], 'summary' => 'D1–D9 per the characterization report.', 'priority' => 'high', 'state' => 'proposed', 'log' => 'Summary.', 'base_revision' => $read['revision']], $bearer);
    $this->assertIsArray($revised, (string) json_encode($revised));
    $this->assertSame('proposed', $revised['state']);
    $this->assertSame('high', $revised['priority']);
    $this->assertSame($requirement['statement'], $revised['statement'], 'Fields not sent are kept.');
    $this->assertGreaterThan($read['revision'], $revised['revision']);
    $this->assertStringContainsString('changed after revision', $this->tool('update_artifact', ['code' => $requirement['code'], 'title' => 'x', 'log' => 'x', 'base_revision' => $read['revision']], $bearer));
    $this->assertStringContainsString('log', $this->tool('update_artifact', ['code' => $requirement['code'], 'title' => 'x'], $bearer));

    // Clients never approve.
    foreach (['approved', 'published'] as $state) {
      $this->assertStringContainsString('Approving stays with people', $this->tool('update_artifact', ['code' => $requirement['code'], 'state' => $state, 'log' => 'x'], $bearer));
    }

    // Removing a link needs explicit confirmation.
    $trace = $read['traces'][0]['trace_link'];
    $this->assertStringContainsString('confirm: true', $this->tool('unlink', ['trace_link' => $trace, 'confirm' => FALSE], $bearer));
    $this->assertSame($trace, $this->tool('unlink', ['trace_link' => $trace, 'confirm' => TRUE], $bearer)['removed']);
    $this->assertCount(1, $this->tool('read', ['code' => $requirement['code']], $bearer)['traces']);

    // The audit log names the operation, the person and the client, not the
    // content.
    $log = $this->container->get('database')->select('watchdog', 'w')->fields('w', ['message', 'variables'])->condition('type', 'divengine_mcp_write')->execute()->fetchAll();
    $entries = array_map(fn ($row) => strtr($row->message, unserialize($row->variables)), $log);
    $this->assertContains(sprintf('create_artifact artifact %s by user %s through MCP client Test client.', $artifact->id(), $this->writer->id()), $entries);
    $this->assertStringNotContainsString('Legacy behaviour', implode("\n", $entries));
  }

  /**
   * Clients revise; people approve and baseline.
   *
   * Revising a baselined requirement leaves the baselined revision as the
   * default until people approve and baseline the new one. Revising an
   * approved one sends it back to Draft, as editing it in Studio does.
   */
  public function testRevisionsNeverReplaceWhatPeopleApproved(): void {
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $move = function (string $code, array $states) use ($storage): int {
      $id = current($storage->getQuery()->accessCheck(FALSE)->condition('artifact_key', $code)->execute());
      foreach ($states as $state) {
        $artifact = $storage->loadRevision($storage->getLatestRevisionId($id));
        $artifact->set('moderation_state', $state)->setNewRevision(TRUE);
        $artifact->save();
      }
      return (int) $id;
    };

    $baselined = $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'Baselined rule', 'statement' => 'Baselined statement.'], $bearer);
    $id = $move($baselined['code'], ['proposed', 'approved', 'published']);
    $storage->resetCache();
    $baseline_revision = (int) $storage->load($id)->getRevisionId();
    $revised = $this->tool('update_artifact', ['code' => $baselined['code'], 'statement' => 'Proposed new statement.', 'log' => 'Proposed change.'], $bearer);
    $this->assertIsArray($revised, (string) json_encode($revised));
    $this->assertSame('draft', $revised['state']);
    $this->assertSame('Proposed new statement.', $revised['statement'], 'Read returns the latest revision.');
    $storage->resetCache();
    $default = $storage->load($id);
    $this->assertSame($baseline_revision, (int) $default->getRevisionId(), 'The baselined revision stays the default.');
    $this->assertSame('published', $default->get('moderation_state')->value);
    $this->assertSame('Baselined statement.', $default->get('field_statement')->value);

    $approved = $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'requirement', 'title' => 'Approved rule', 'statement' => 'Approved statement.'], $bearer);
    $move($approved['code'], ['proposed', 'approved']);
    $this->assertSame('draft', $this->tool('update_artifact', ['code' => $approved['code'], 'statement' => 'Changed.', 'log' => 'Change.'], $bearer)['state'], 'A changed approved requirement needs approval again.');
    $this->assertStringContainsString('Approving stays with people', $this->tool('update_artifact', ['code' => $approved['code'], 'state' => 'approved', 'log' => 'Approve it back.'], $bearer));
  }

  /**
   * A retry with an idempotency key rechecks access (review of #78).
   *
   * Only the created artifact's ID is remembered. A person who left the
   * Development keeps their token, scope and role, but the retry is refused
   * like any request about a Development they cannot see, and says nothing
   * about the record. With access restored the retry returns the same record
   * instead of a duplicate; a deleted record is never described or created
   * again.
   */
  public function testIdempotentRetryRechecksAccess(): void {
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $alpha = $this->code($this->alpha);
    $create = ['development' => $alpha, 'type' => 'requirement', 'title' => 'Confidential pricing rule', 'statement' => 'Discounts above 40% need approval.', 'idempotency_key' => 'retry-1'];
    $created = $this->tool('create_artifact', $create, $bearer);
    $this->assertIsArray($created, (string) json_encode($created));
    $stored = $this->container->get('keyvalue.expirable')->get('divengine_mcp_write.idempotency')->get($this->writer->id() . ':retry-1');
    $this->assertSame(['request', 'id'], array_keys($stored), 'Only the request hash and the ID are remembered.');

    // The writer leaves the Development; token, scope and role stay.
    $development = Development::load($this->alpha->id());
    $development->set('members', [])->save();
    $denied = $this->tool('create_artifact', $create, $bearer);
    $this->assertSame("Error: No Development with code $alpha is visible to you.", $denied, 'The same answer as for a Development that does not exist.');
    foreach ([$created['code'], 'Confidential', 'Discounts'] as $secret) {
      $this->assertStringNotContainsString($secret, $denied);
    }
    $this->assertStringNotContainsString('Confidential', (string) $this->tool('create_artifact', ['idempotency_key' => 'retry-1', 'title' => 'x'] + $create, $bearer));

    // Access restored: the retry returns the record, as it is now.
    $development = Development::load($this->alpha->id());
    $development->set('members', [$this->writer->id()])->save();
    $again = $this->tool('create_artifact', $create, $bearer);
    $this->assertIsArray($again, (string) json_encode($again));
    $this->assertSame($created['code'], $again['code']);
    $this->assertTrue($again['replayed']);
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $this->assertSame(1, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute(), 'No duplicate.');

    // A deleted record is not described, and nothing is created again.
    $storage->delete($storage->loadMultiple());
    $gone = $this->tool('create_artifact', $create, $bearer);
    $this->assertSame('Error: This idempotency key was already used, and what it created is not available to you. Use a new key to create a record.', $gone);
    $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * The engineering_contributor permissions of the #72 pilot account.
   */
  const CONTRIBUTOR = [
    'access divengine',
    'use unified process',
    'view artifact',
    'view artifact revisions',
    'view latest version',
    'create requirement artifact',
    'create decision artifact',
    'create evidence artifact',
    'edit requirement artifact',
    'edit decision artifact',
    'edit evidence artifact',
    'view trace links',
    'view trace link revisions',
    'create trace links',
    'link external work',
    'use requirement_lifecycle transition draft',
    'use requirement_lifecycle transition propose',
    'use artifact_lifecycle transition create_new_draft',
    'use artifact_lifecycle transition submit_for_review',
  ];

  /**
   * A least-privilege contributor submits revisions for review (#83).
   *
   * Update access is decided on the revision as it is, and the transition
   * from its state to the requested one is checked by moderation validation.
   * Before, access was asked after the new state was set, so submitting a
   * decision or evidence for review needed transitions out of Review.
   */
  public function testLeastPrivilegeContributorSubmitsForReview(): void {
    $contributor = $this->createUser(static::CONTRIBUTOR, 'contributor');
    $contributor->addRole('mcp_user');
    $contributor->addRole('mcp_writer');
    $contributor->save();
    $alpha = Development::load($this->alpha->id());
    $alpha->get('members')->appendItem($contributor->id());
    $alpha->save();
    $bearer = 'Bearer ' . $this->oauthToken($contributor, ['mcp', 'mcp_write']);
    $development = $this->code($this->alpha);
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $history = function (string $code) use ($storage): array {
      $id = current($storage->getQuery()->accessCheck(FALSE)->condition('artifact_key', $code)->execute());
      $out = [];
      foreach ($this->container->get('database')->query('SELECT revision_id FROM {artifact_revision} WHERE id = :id ORDER BY revision_id', [':id' => $id])->fetchCol() as $rid) {
        $revision = $storage->loadRevision($rid);
        $out[] = [$revision->get('moderation_state')->value, (string) $revision->getRevisionUserId(), $revision->getRevisionLogMessage()];
      }
      return $out;
    };

    foreach (['decision', 'evidence'] as $type) {
      $draft = $this->tool('create_artifact', ['development' => $development, 'type' => $type, 'title' => "Pilot $type"], $bearer);
      $this->assertIsArray($draft, (string) json_encode($draft));
      $this->assertSame('draft', $draft['state']);

      // Draft to Review, with the stale-write guard.
      $this->assertStringContainsString('changed after revision', (string) $this->tool('update_artifact', ['code' => $draft['code'], 'state' => 'review', 'log' => 'x', 'base_revision' => $draft['revision'] - 1], $bearer));
      $review = $this->tool('update_artifact', ['code' => $draft['code'], 'summary' => 'Ready.', 'state' => 'review', 'log' => 'Submit for review.', 'base_revision' => $draft['revision']], $bearer);
      $this->assertIsArray($review, "$type to review: " . json_encode($review));
      $this->assertSame('review', $review['state']);
      $this->assertSame([
        ['draft', (string) $contributor->id(), 'Through MCP (Test client): Created.'],
        ['review', (string) $contributor->id(), 'Through MCP (Test client): Submit for review.'],
      ], $history($draft['code']), "$type: history and revision users.");

      // In Review the contributor has no transition (request changes and
      // approval belong to reviewers): no revision is possible, in any state.
      foreach (['draft', 'review'] as $state) {
        $this->assertSame("Error: You may not revise {$draft['code']} while it is review.", $this->tool('update_artifact', ['code' => $draft['code'], 'summary' => 'Again.', 'state' => $state, 'log' => 'x'], $bearer));
      }
      // Proposed is not a state of their workflow.
      $other = $this->tool('create_artifact', ['development' => $development, 'type' => $type, 'title' => "Other $type"], $bearer);
      $this->assertStringContainsString('moderation_state', (string) $this->tool('update_artifact', ['code' => $other['code'], 'state' => 'proposed', 'log' => 'x'], $bearer));
      $this->assertCount(1, $history($other['code']), 'A refused revision stores nothing.');

      // Creating directly in Review is the same transition (Draft to Review)
      // and the workflow allows it.
      $direct = $this->tool('create_artifact', ['development' => $development, 'type' => $type, 'title' => "Direct $type", 'state' => 'review'], $bearer);
      $this->assertSame('review', $direct['state']);
    }

    // Requirements: Draft to Proposed; Review is not theirs.
    $requirement = $this->tool('create_artifact', ['development' => $development, 'type' => 'requirement', 'title' => 'Pilot requirement', 'statement' => 'The system shall.'], $bearer);
    $this->assertSame('proposed', $this->tool('update_artifact', ['code' => $requirement['code'], 'state' => 'proposed', 'log' => 'Propose.'], $bearer)['state']);
    $this->assertStringContainsString('moderation_state', (string) $this->tool('update_artifact', ['code' => $requirement['code'], 'state' => 'review', 'log' => 'x'], $bearer));

    // Approving is never possible.
    foreach (['approved', 'published'] as $state) {
      $this->assertStringContainsString('Approving stays with people', (string) $this->tool('update_artifact', ['code' => $requirement['code'], 'state' => $state, 'log' => 'x'], $bearer));
    }

    // Outside its Development, without the scope or without the role: no.
    $hidden = Artifact::create(['type' => 'decision', 'title' => 'Beta decision', 'development' => $this->beta->id()]);
    $hidden->save();
    $this->assertStringStartsWith('Error: No record with code', (string) $this->tool('update_artifact', ['code' => $hidden->get('artifact_key')->value, 'state' => 'review', 'log' => 'x'], $bearer));
    $read_only = 'Bearer ' . $this->oauthToken($contributor, ['mcp']);
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('update_artifact', ['code' => $requirement['code'], 'log' => 'x'], $read_only));
    $editor = $this->createUser(static::CONTRIBUTOR);
    $editor->addRole('mcp_user');
    $editor->save();
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('update_artifact', ['code' => $requirement['code'], 'log' => 'x'], 'Bearer ' . $this->oauthToken($editor, ['mcp', 'mcp_write'])));
  }

  public function testOnlyWriteTokensOfWritersWrite(): void {
    $create = ['development' => $this->code($this->alpha), 'type' => 'decision', 'title' => 'Drupal is canonical'];

    // A read-only token of the writer.
    $read_only = 'Bearer ' . $this->oauthToken($this->writer, ['mcp']);
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames($read_only))));
    $this->assertStringContainsString('JSON-RPC error', (string) $this->tool('create_artifact', $create, $read_only));

    // The shared service token.
    $bot = $this->createUser([], 'mcp_bot');
    $bot->addRole('mcp_agent');
    $bot->save();
    $this->grantMcpToken($bot);
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames($this->basic()))));
    $this->assertStringContainsString('JSON-RPC error', (string) $this->tool('create_artifact', $create, $this->basic()));

    // A write-scoped token of someone without the MCP writer role.
    $editor = $this->person(['mcp_user']);
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames('Bearer ' . $this->oauthToken($editor, ['mcp', 'mcp_write'])))));

    // User 1 has every permission, but not the scope on a read-only token.
    $admin = User::load(1);
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames('Bearer ' . $this->oauthToken($admin, ['mcp'])))));

    // An MCP writer without the ordinary permission to create artifacts.
    $writer_only = $this->createUser(['use unified process', 'view artifact']);
    $writer_only->addRole('mcp_user');
    $writer_only->addRole('mcp_writer');
    $writer_only->save();
    $alpha = Development::load($this->alpha->id());
    $alpha->get('members')->appendItem($writer_only->id());
    $alpha->save();
    $this->assertStringContainsString('You may not create', (string) $this->tool('create_artifact', $create, 'Bearer ' . $this->oauthToken($writer_only, ['mcp', 'mcp_write'])));

    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('artifact')->getQuery()->accessCheck(FALSE)->count()->execute(), 'Nothing was written.');

    // A revoked write token stops at once.
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->assertIsArray($this->tool('developments', [], $bearer));
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_token');
    $storage->delete($storage->loadByProperties(['bundle' => 'access_token', 'auth_user_id' => $this->writer->id()]));
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $bearer)->getStatusCode());
  }

  /**
   * The MCP writer role must be held explicitly, by everyone (#79).
   *
   * User 1 and administrator roles have every permission, but without the
   * role their write-scoped tokens neither list nor run engineering tools.
   * With the role they reach only the enumerated tools; removing it stops
   * them on the next request, with the same token.
   */
  public function testWriterRoleIsRequiredEvenForAdministrators(): void {
    $create = ['development' => $this->code($this->beta), 'type' => 'decision', 'title' => 'Admin decision'];
    $artifacts = $this->container->get('entity_type.manager')->getStorage('artifact');
    $count = fn () => (int) $artifacts->getQuery()->accessCheck(FALSE)->count()->execute();

    // User 1, and a person with an administrator role, with write-scoped
    // tokens but without the MCP writer role.
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $administrator = $this->createUser([], 'site_admin');
    $administrator->addRole('administrator');
    $administrator->save();
    $root = User::load(1);
    foreach (['user 1' => $root, 'administrator' => $administrator] as $who => $account) {
      $this->assertTrue($account->hasPermission('write engineering records through mcp'), "$who has every permission.");
      $bearer = 'Bearer ' . $this->oauthToken($account, ['mcp', 'mcp_write']);
      $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames($bearer))), "$who lists no engineering tool.");
      foreach (['create_artifact' => $create, 'read' => ['code' => 'REQ-0001'], 'developments' => []] as $tool => $arguments) {
        $answer = (string) $this->tool($tool, $arguments, $bearer);
        $this->assertStringStartsWith('JSON-RPC error', $answer, "$who cannot call $tool directly.");
        $this->assertStringNotContainsString('Beta', $answer);
      }
    }
    $this->assertSame(0, $count(), 'Nothing was written.');

    // With the role, user 1 reaches the enumerated tools, and only those
    // write.
    $root->addRole('mcp_writer');
    $root->save();
    $bearer = 'Bearer ' . $this->oauthToken($root, ['mcp', 'mcp_write']);
    $engineering = array_values(preg_grep('/^engineering_/', $this->toolNames($bearer)));
    sort($engineering);
    $this->assertSame(['engineering_create_artifact', 'engineering_developments', 'engineering_link', 'engineering_read', 'engineering_register_external', 'engineering_unlink', 'engineering_update_artifact'], $engineering);
    $created = $this->tool('create_artifact', $create, $bearer);
    $this->assertIsArray($created, (string) json_encode($created));
    $this->assertSame(1, $count());

    // Deleting the MCP writer role leaves the accounts unsaved and their
    // tokens valid. The role is read from the account on every request, so
    // user 1, who keeps every permission, loses the tools at once.
    $writer_bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $this->assertContains('engineering_developments', $this->toolNames($writer_bearer));
    $permissions = Role::load('mcp_writer')->getPermissions();
    Role::load('mcp_writer')->delete();
    $this->assertSame(200, $this->mcpRequest('tools/list', [], $bearer)->getStatusCode(), 'The token is still valid.');
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames($writer_bearer))), 'An ordinary writer loses the tools too.');
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('developments', [], $writer_bearer));
    $this->assertSame([], array_values(preg_grep('/^engineering_/', $this->toolNames($bearer))));
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('read', ['code' => $created['code']], $bearer));
    $this->assertStringStartsWith('JSON-RPC error', (string) $this->tool('create_artifact', $create + ['idempotency_key' => 'k'], $bearer));
    $this->assertSame(1, $count());

    // Removing the role from an account: simple_oauth also revokes the
    // person's tokens whenever the account is saved.
    $role = Role::create(['id' => 'mcp_writer', 'label' => 'MCP writer']);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $root = User::load(1);
    $root->addRole('mcp_writer');
    $root->save();
    $bearer = 'Bearer ' . $this->oauthToken($root, ['mcp', 'mcp_write']);
    $this->assertContains('engineering_developments', $this->toolNames($bearer));
    $root = User::load(1);
    $root->removeRole('mcp_writer');
    $root->save();
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $bearer)->getStatusCode());
    $this->assertSame(1, $count());

    // A blocked account is refused, with or without the role.
    $writer = User::load($this->writer->id());
    $writer->block();
    $writer->save();
    $this->assertSame(401, $this->mcpRequest('tools/list', [], $writer_bearer)->getStatusCode());
  }

  /**
   * Browser sessions never reach engineering tools, whatever the roles.
   */
  public function testSessionsNeverWrite(): void {
    $root = User::load(1);
    $root->addRole('mcp_writer');
    $root->save();
    $plugins = $this->container->get('plugin.manager.mcp');
    foreach ([$root, $this->writer] as $account) {
      $this->setCurrentUser($account);
      $this->assertFalse($plugins->createInstance('engineering')->hasAccess()->isAllowed(), $account->getAccountName());
    }
  }

  public function testDevelopmentBoundariesHold(): void {
    $bearer = 'Bearer ' . $this->oauthToken($this->writer, ['mcp', 'mcp_write']);
    $beta = $this->code($this->beta);
    $hidden = Artifact::create(['type' => 'requirement', 'title' => 'Beta secret requirement', 'development' => $this->beta->id()]);
    $hidden->save();
    $hidden_code = $hidden->get('artifact_key')->value;

    // A Development or record the writer cannot see answers like a missing
    // one and names nothing.
    $answers = [
      $this->tool('create_artifact', ['development' => $beta, 'type' => 'requirement', 'title' => 'x'], $bearer),
      $this->tool('register_external', ['development' => $beta, 'url' => 'https://github.com/a/b/pull/1'], $bearer),
      $this->tool('read', ['code' => $hidden_code], $bearer),
      $this->tool('update_artifact', ['code' => $hidden_code, 'title' => 'x', 'log' => 'x'], $bearer),
    ];
    $this->assertSame("Error: No Development with code $beta is visible to you.", $answers[0]);
    $this->assertSame($answers[0], $answers[1]);
    $this->assertSame("Error: No record with code $hidden_code is visible to you.", $answers[2]);
    $this->assertSame($answers[2], $answers[3]);
    $this->assertSame($answers[2], $this->tool('read', ['code' => 'REQ-9999'] + [], $bearer) === "Error: No record with code REQ-9999 is visible to you." ? $answers[2] : 'leak');
    $this->assertStringNotContainsString('secret', implode(' ', $answers));

    $own = $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'evidence', 'title' => 'Run 42'], $bearer);
    $this->assertSame("Error: No record with code $hidden_code is visible to you.", $this->tool('link', ['source' => $own['code'], 'type' => 'verifies', 'target' => $hidden_code], $bearer));

    // Even for a member of both, links stay inside one Development.
    $beta_development = Development::load($this->beta->id());
    $beta_development->get('members')->appendItem($this->writer->id());
    $beta_development->save();
    $this->assertStringContainsString('stay inside that Development', (string) $this->tool('link', ['source' => $own['code'], 'type' => 'verifies', 'target' => $hidden_code], $bearer));

    // Only the enumerated types.
    $this->assertStringContainsString('Only these types', (string) $this->tool('create_artifact', ['development' => $this->code($this->alpha), 'type' => 'risk', 'title' => 'x'], $bearer));
  }

  public function testRecipeGrantsNoWrites(): void {
    $this->assertSame([], array_values($this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('roles', 'mcp_writer')->condition('uid', $this->writer->id(), '<>')->execute()), 'Nobody else is an MCP writer.');
    $this->assertFalse($this->container->get('entity_type.manager')->getStorage('user_role')->load('mcp_agent')->hasPermission('write engineering records through mcp'));
    $this->assertFalse($this->container->get('entity_type.manager')->getStorage('user_role')->load('mcp_user')->hasPermission('write engineering records through mcp'));
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->load('mcp_write');
    $this->assertSame('mcp_editor', $scope->getGranularity()->getConfiguration()['role']);
  }

}
