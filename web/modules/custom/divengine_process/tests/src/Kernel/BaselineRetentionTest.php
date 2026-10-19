<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Safe counterpart of the audit's protected_revision_exists_after_delete. */
#[RunTestsInSeparateProcesses]
class BaselineRetentionTest extends KernelTestBase {

  use UserCreationTrait;
  use BaselineFixtureTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  protected function setUp(): void {
    parent::setUp();
    $this->installBaselineFixture();
  }

  private function assertRefused(callable $operation): void {
    try {
      $operation();
      $this->fail('Historical mutation should have been refused.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('baseline', strtolower($e->getMessage()));
    }
  }

  public function testProtectedAndUnprotectedRevisionDeletion(): void {
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $recorder = $this->container->get('divengine_process.baselines');
    $items = $recorder->items($baseline);
    foreach ([$artifacts[0], $trace] as $record) {
      $storage = $this->container->get('entity_type.manager')->getStorage($record->getEntityTypeId());
      $protected = $record->getRevisionId();
      $record->set($record->getEntityTypeId() === 'artifact' ? 'title' : 'rationale', 'Second revision')->save();
      $unprotected = $record->getRevisionId();
      $record->set($record->getEntityTypeId() === 'artifact' ? 'title' : 'rationale', 'Third revision')->save();
      $this->assertRefused(fn () => $storage->deleteRevision($protected));
      $this->assertNotNull($storage->loadRevision($protected));
      $this->assertSame('Third revision', $storage->loadUnchanged($record->id())->get($record->getEntityTypeId() === 'artifact' ? 'title' : 'rationale')->value);
      $storage->deleteRevision($unprotected);
      $this->assertNull($storage->loadRevision($unprotected), 'Unprotected non-default revision can still be deleted.');
    }
    $this->assertSame($items, $recorder->items($baseline));
  }

  public function testEntityAndBatchDeletionPreserveAllRecordsAndItems(): void {
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $loose = Artifact::create(['type' => 'risk', 'title' => 'Not captured']); $loose->save();
    $this->assertRefused(fn () => $storage->delete([$loose->id() => $loose, $artifacts[0]->id() => $artifacts[0]]));
    $this->assertNotNull($storage->loadUnchanged($loose->id()), 'A mixed batch is rejected before deleting its unprotected member.');
    $this->assertRefused(fn () => $artifacts[0]->delete());
    $this->assertRefused(fn () => $trace->delete());
    $this->assertRefused(fn () => $baseline->delete());
    $this->assertNotNull($storage->loadUnchanged($artifacts[0]->id()));
    $this->assertCount(4, $this->container->get('divengine_process.baselines')->items($baseline));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('trace_link')->loadUnchanged($trace->id()));
    $loose->delete();
    $this->assertNull($storage->loadUnchanged($loose->id()), 'Unprotected entities remain deletable.');
    // Metadata editing remains supported and does not release protections.
    $baseline->set('name', 'Renamed')->set('notes', 'Review note')->save();
    $this->assertCount(4, $this->container->get('divengine_process.baselines')->items($baseline));
  }

  public function testProtectedArtifactCannotBeOverwrittenDuringSync(): void {
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $artifact = $artifacts[0];
    $revision = $artifact->getRevisionId();
    $artifact->setSyncing(TRUE); // Core moderation normally skips syncing saves.
    $artifact->setTitle('Attempted overwrite')->setNewRevision(FALSE);
    $this->assertRefused(fn () => $artifact->save());
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $this->assertSame('First', $storage->loadRevision($revision)->label());
    $this->assertSame('First', $storage->loadUnchanged($artifact->id())->label());
  }

  public function testReadOnlyDiagnosticReportsMissingHistoryWithoutRepair(): void {
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $db = $this->container->get('database');
    // Deliberately corrupt only this disposable fixture using raw SQL. Storage
    // APIs correctly refuse it; this also documents the raw-SQL trust boundary.
    $db->update('divengine_process_baseline_item')->fields(['revision_id' => 999999])->condition('entity_type', 'trace_link')->execute();
    $before = $db->select('divengine_process_baseline_item', 'i')->fields('i')->execute()->fetchAll();
    ob_start();
    require dirname($this->root) . '/scripts/diagnose-baselines.php';
    $report = json_decode(ob_get_clean(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertTrue($report['read_only']);
    $this->assertSame('on', $report['transaction_read_only']);
    $this->assertSame('repeatable read', $report['transaction_isolation']);
    $this->assertSame(1, $report['baselines']);
    $this->assertSame(4, $report['items']);
    $this->assertSame(1, $report['issue_counts']['missing_revision']);
    $this->assertEquals($before, $db->select('divengine_process_baseline_item', 'i')->fields('i')->execute()->fetchAll());
  }

  public function testFieldPurgeRetainsCapturedValuesButAllowsUnprotectedValues(): void {
    $definition = \Drupal\field\Entity\FieldStorageConfig::create(['entity_type' => 'artifact', 'field_name' => 'field_history_note', 'type' => 'string']);
    $definition->save();
    $fields = [];
    foreach (['requirement', 'risk'] as $bundle) {
      $fields[$bundle] = \Drupal\field\Entity\FieldConfig::create(['field_storage' => $definition, 'bundle' => $bundle]);
      $fields[$bundle]->save();
    }
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $artifact = $artifacts[0];
    $artifact->set('field_history_note', 'Captured value')->save();
    $development = $artifact->get('development')->entity;
    \Drupal::service('divengine_process.baselines')->take($development, 'With field value');
    $loose = Artifact::create(['type' => 'risk', 'title' => 'Loose field value', 'field_history_note' => 'Not captured']); $loose->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('artifact');
    $repository = $this->container->get('entity_field.deleted_fields_repository');
    $risk_uuid = $fields['risk']->uuid();
    $fields['risk']->delete();
    $deleted = $repository->getFieldDefinitions();
    $this->assertGreaterThan(0, $storage->purgeFieldData($deleted[$risk_uuid], 100), 'Unprotected bundle values can be purged.');
    $requirement_uuid = $fields['requirement']->uuid();
    $fields['requirement']->delete();
    $deleted = $repository->getFieldDefinitions();
    $this->assertRefused(fn () => $storage->purgeFieldData($deleted[$requirement_uuid], 100));
    $this->assertRefused(fn () => $storage->finalizePurge($deleted[$requirement_uuid]->getFieldStorageDefinition()));
    $field_storage = $deleted[$requirement_uuid]->getFieldStorageDefinition();
    $definitions = $this->container->get('entity_field.manager')->getFieldStorageDefinitions('artifact');
    $definitions[$field_storage->getName()] = $field_storage;
    $table = $storage->getTableMapping($definitions)->getDedicatedRevisionTableName($field_storage, $field_storage->isDeleted());
    $this->assertTrue($this->container->get('database')->schema()->tableExists($table));
    $this->assertGreaterThan(0, (int) $this->container->get('database')->select($table, 'f')->countQuery()->execute()->fetchField());
  }

}
