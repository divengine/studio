<?php

declare(strict_types=1);

namespace Drupal\Tests\studio_audit\Kernel;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\ProcessActivity;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\Tests\divengine_process\Kernel\DevelopmentTest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Disposable diagnostic probes: assertions reproduce the observed behavior. */
#[RunTestsInSeparateProcesses]
class AuditInvariantProbeTest extends DevelopmentTest {

  private function record(string $name, array $facts): void {
    file_put_contents(getenv('STUDIO_AUDIT_EVIDENCE'), json_encode(['probe' => $name] + $facts, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
  }

  private function artifact(Development $development, string $title): Artifact {
    $artifact = Artifact::create(['type' => 'requirement', 'title' => $title, 'development' => $development->id()]);
    $artifact->save();
    return $artifact;
  }

  public function testAuditStateSaveBypassesValidation(): void {
    $development = $this->createDevelopment();
    $activity = ProcessActivity::create(['development' => $development->id(), 'name' => 'Audit activity']);
    $activity->save();
    $activity->set('state', 'completed');
    $violations = $this->violations($activity);
    $activity->save();
    $saved = ProcessActivity::load($activity->id());
    $this->record('direct_state_save', ['violations' => $violations, 'saved_state' => $saved->getState()]);
    $this->assertSame('completed', $saved->getState());
  }

  public function testAuditArtifactCodeCanBeChanged(): void {
    $artifact = $this->artifact($this->createDevelopment(), 'Code example');
    $before = $artifact->getArtifactKey();
    $artifact->setArtifactKey('CHANGED-BY-DIRECT-SAVE');
    $artifact->save();
    $after = Artifact::load($artifact->id())->getArtifactKey();
    $this->record('artifact_key', ['before' => $before, 'after' => $after]);
    $this->assertSame('CHANGED-BY-DIRECT-SAVE', $after);
  }

  public function testAuditCrossDevelopmentTracePersists(): void {
    $a = $this->artifact($this->createDevelopment('A'), 'A');
    $b = $this->artifact($this->createDevelopment('B'), 'B');
    $trace = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $a->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $b->id(), 'link_type' => 'references']);
    $violations = $this->violations($trace);
    $trace->save();
    $this->record('cross_development_trace', ['violations' => $violations, 'saved' => (bool) TraceLink::load($trace->id())]);
    $this->assertNotNull(TraceLink::load($trace->id()));
  }

  public function testAuditBaselineRevisionDeletionAndPublishedEdits(): void {
    $development = $this->createDevelopment();
    $artifact = $this->artifact($development, 'Original');
    $artifact->set('moderation_state', 'published')->save();
    $storage = \Drupal::entityTypeManager()->getStorage('artifact');
    $artifact = $storage->loadUnchanged($artifact->id());
    $protected_revision = $artifact->getRevisionId();
    $baseline = \Drupal::service('divengine_process.baselines')->take($development, 'Audit baseline');
    $artifact->setTitle('Changed while still published')->save();
    $current = $storage->loadUnchanged($artifact->id());
    $old = $storage->loadRevision($protected_revision);
    $protected = \Drupal::service('divengine_process.baselines')->protectsRevision('artifact', $protected_revision);
    $storage->deleteRevision($protected_revision);
    $remaining = $storage->loadRevision($protected_revision);
    $this->record('baseline_history', ['items' => \Drupal::service('divengine_process.baselines')->items($baseline), 'new_revision_created' => $current->getRevisionId() !== $protected_revision, 'new_default_state' => $current->get('moderation_state')->value, 'old_title_before_delete' => $old?->label(), 'was_protected' => $protected, 'protected_revision_exists_after_delete' => (bool) $remaining]);
    $this->assertNull($remaining);
  }

  public function testAuditEmptyBaselineAndDirectDeletion(): void {
    $development = $this->createDevelopment();
    $baseline = \Drupal::service('divengine_process.baselines')->take($development, 'Empty baseline');
    $id = $baseline->id();
    $items = \Drupal::service('divengine_process.baselines')->items($baseline);
    $baseline->delete();
    $this->record('baseline_empty_delete', ['items' => $items, 'deleted' => !\Drupal::entityTypeManager()->getStorage('baseline')->load($id)]);
    $this->assertSame([], $items);
  }
}
