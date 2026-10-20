<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\ChangeRequest;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\Iteration;
use Drupal\divengine_process\Entity\Milestone;
use Drupal\divengine_process\Entity\ProcessActivity;
use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C05: lifecycle and moderation states hold for every writer.
 */
#[RunTestsInSeparateProcesses]
class StateInvariantTest extends KernelTestBase {

  use BaselineFixtureTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  protected function setUp(): void {
    parent::setUp();
    $this->installBaselineFixture();
  }

  /**
   * A record of each Unified Process lifecycle: a state set directly, a
   * move no transition declares (NULL when every move is declared), and a
   * valid transition with its result.
   */
  public static function records(): array {
    return [
      'development' => ['development', 'closed', 'closed', 'activate', 'active'],
      'iteration' => ['iteration', 'done', 'done', 'start', 'active'],
      'milestone' => ['milestone', 'reached', NULL, 'reach', 'reached'],
      'activity' => ['process_activity', 'completed', 'completed', 'start', 'in_progress'],
      'change request' => ['change_request', 'closed', 'closed', 'triage', 'triaged'],
    ];
  }

  protected function record(string $entity_type_id): StatefulInterface {
    $development = Development::create(['name' => 'States']);
    $development->save();
    $record = match ($entity_type_id) {
      'development' => $development,
      'iteration' => Iteration::create(['development' => $development->id(), 'phase' => $development->get('current_phase')->target_id, 'name' => 'I9']),
      'milestone' => Milestone::load(current(\Drupal::entityQuery('milestone')->accessCheck(FALSE)->condition('development', $development->id())->range(0, 1)->execute())),
      'process_activity' => ProcessActivity::create(['development' => $development->id(), 'name' => 'Write the vision']),
      'change_request' => ChangeRequest::create(['development' => $development->id(), 'name' => 'Change', 'description' => 'A change.']),
    };
    if ($record->isNew()) {
      $record->save();
    }
    return \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($record->id());
  }

  #[DataProvider('records')]
  public function testDirectStateChangeIsNotStored(string $entity_type_id, string $illegal, ?string $undeclared, string $transition, string $result): void {
    $record = $this->record($entity_type_id);
    $initial = $record->getState();
    $record->set('state', $illegal);
    $this->assertGreaterThan(0, $record->validate()->count(), 'Validation reports it, as before.');
    try {
      $record->save();
      $this->fail("$entity_type_id must not store a state set directly.");
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('changes only through its transitions', $e->getMessage());
    }
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $this->assertSame($initial, $storage->loadUnchanged($record->id())->getState());

    if ($undeclared === NULL) {
      return;
    }
    // Marking the change as a transition does not make an undeclared move.
    $record = $storage->loadUnchanged($record->id());
    $record->setStateThroughTransition($undeclared);
    try {
      $record->save();
      $this->fail('An undeclared move must not be stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('No transition', $e->getMessage());
    }
    $this->assertSame($initial, $storage->loadUnchanged($record->id())->getState());
  }

  #[DataProvider('records')]
  public function testValidTransitionIsStoredAndAudited(string $entity_type_id, string $illegal, ?string $undeclared, string $transition, string $result): void {
    $record = $this->record($entity_type_id);
    \Drupal::service('divengine_process.lifecycle')->apply($record, $transition);
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $stored = $storage->loadUnchanged($record->id());
    $this->assertSame($result, $stored->getState());
    $this->assertFalse($record->isTransitioning());
    if ($stored->getEntityType()->isRevisionable()) {
      $this->assertStringContainsString('→', (string) $stored->getRevisionLogMessage(), 'The transition is in the revision log.');
      $this->assertSame((int) \Drupal::currentUser()->id(), (int) $stored->getRevisionUserId());
    }
  }

  public function testUnknownStateIsNotStoredOnCreation(): void {
    $development = Development::create(['name' => 'Bad state', 'state' => 'imaginary']);
    $this->expectException(EntityStorageException::class);
    $development->save();
  }

  public function testOtherFieldsStillSaveDirectly(): void {
    $record = $this->record('process_activity');
    $record->set('name', 'Write and review the vision');
    $record->save();
    $this->assertSame('Write and review the vision', \Drupal::entityTypeManager()->getStorage('process_activity')->loadUnchanged($record->id())->label());
  }

  public function testApprovedArtifactStaysDefaultUntilApprovedAgain(): void {
    $development = Development::create(['name' => 'Approved content']);
    $development->save();
    $requirement = Artifact::create(['type' => 'requirement', 'title' => 'Approved wording', 'development' => $development->id(), 'moderation_state' => 'published']);
    $requirement->save();
    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $approved = (int) $requirement->getRevisionId();

    // Editing approved content in place is not a transition of its workflow.
    $requirement->setTitle('Changed without review');
    try {
      $requirement->save();
      $this->fail('Approved content must not change in place.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('no such transition', $e->getMessage());
    }
    $this->assertSame($approved, (int) $storage->loadUnchanged($requirement->id())->getRevisionId());

    // A new draft is a pending revision; the approved one stays in force.
    $draft = $storage->loadUnchanged($requirement->id());
    $draft->setTitle('Proposed wording');
    $draft->set('moderation_state', 'draft');
    $draft->setNewRevision();
    $draft->save();
    $default = $storage->loadUnchanged($requirement->id());
    $this->assertSame($approved, (int) $default->getRevisionId());
    $this->assertSame('Approved wording', $default->getTitle());
    $latest = $storage->loadRevision($storage->getLatestRevisionId($requirement->id()));
    $this->assertSame('Proposed wording', $latest->getTitle());

    // Skipping the review is refused even for a pending revision.
    $latest->set('moderation_state', 'published');
    try {
      $latest->save();
      $this->fail('Draft to Baselined skips Proposed and Approved.');
    }
    catch (EntityStorageException) {
    }
    $this->assertSame($approved, (int) $storage->loadUnchanged($requirement->id())->getRevisionId());

    // The workflow moves it forward, and every step is a revision.
    foreach (['proposed', 'approved', 'published'] as $state) {
      $latest = $storage->loadRevision($storage->getLatestRevisionId($requirement->id()));
      $latest->set('moderation_state', $state);
      $latest->setNewRevision();
      $latest->setRevisionUserId((int) \Drupal::currentUser()->id());
      $latest->save();
    }
    $default = $storage->loadUnchanged($requirement->id());
    $this->assertSame('Proposed wording', $default->getTitle());
    $this->assertSame('published', $default->get('moderation_state')->value);
    $this->assertSame((int) \Drupal::currentUser()->id(), (int) $default->getRevisionUserId());
  }

  public function testSynchronizationKeepsDrupalSemantics(): void {
    $risk = Artifact::create(['type' => 'risk', 'title' => 'Imported risk', 'moderation_state' => 'published']);
    $risk->save();
    $risk->setTitle('Imported risk, synchronized');
    $risk->setSyncing(TRUE);
    $risk->save();
    $this->assertSame('Imported risk, synchronized', \Drupal::entityTypeManager()->getStorage('artifact')->loadUnchanged($risk->id())->getTitle());
  }

}
