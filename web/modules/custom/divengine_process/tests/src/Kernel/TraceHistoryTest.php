<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Safe counterpart of the audit's trace_revision_overwrite diagnostic. */
#[RunTestsInSeparateProcesses]
class TraceHistoryTest extends KernelTestBase {

  use UserCreationTrait;
  use BaselineFixtureTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  protected function setUp(): void {
    parent::setUp();
    $this->installBaselineFixture();
  }

  public function testEditsPreserveCapturedRationaleTypeAndEnds(): void {
    [$baseline, $trace, $artifacts] = $this->baselineFixture();
    $id = $trace->id(); $uuid = $trace->uuid(); $revision = $trace->getRevisionId();
    $items = \Drupal::service('divengine_process.baselines')->items($baseline);
    $trace->set('rationale', 'New rationale')->set('link_type', 'documents')->set('target_entity_id', $artifacts[2]->id());
    $trace->setNewRevision(FALSE); // A caller must not be able to opt out.
    $trace->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('trace_link');
    $old = $storage->loadRevision($revision);
    $this->assertNotSame($revision, $trace->getRevisionId());
    $this->assertSame($id, $trace->id()); $this->assertSame($uuid, $trace->uuid());
    $this->assertSame('Original rationale', $old->get('rationale')->value);
    $this->assertSame('references', $old->get('link_type')->value);
    $this->assertSame((int) $artifacts[1]->id(), $old->getTargetEntityId());
    $this->assertSame('New rationale', $storage->loadUnchanged($id)->get('rationale')->value);
    $this->assertSame($items, \Drupal::service('divengine_process.baselines')->items($baseline));
    // Saving a loaded historical revision is a new revision too, even if the
    // caller tries to replace the old protected row instead of using the UI.
    $old->set('rationale', 'Edited old revision')->setNewRevision(FALSE);
    $old->isDefaultRevision(TRUE); $old->save();
    $this->assertNotSame($revision, $old->getRevisionId());
    $this->assertSame('Original rationale', $storage->loadRevision($revision)->get('rationale')->value);
    $this->assertSame($uuid, $storage->loadUnchanged($id)->uuid());
  }

  public function testRevertAndFormSaveAppendHistory(): void {
    [$baseline, $trace] = $this->baselineFixture();
    $original_revision = $trace->getRevisionId();
    $storage = $this->container->get('entity_type.manager')->getStorage('trace_link');
    $form = $this->container->get('entity_type.manager')->getFormObject('trace_link', 'edit')->setEntity($trace);
    $trace->set('rationale', 'Form edit');
    $build = []; $state = new FormState();
    $form->save($build, $state);
    $edited_revision = $trace->getRevisionId();
    $this->assertNotSame($original_revision, $edited_revision);
    $revert = $storage->loadRevision($original_revision);
    $revert->setNewRevision(TRUE); $revert->isDefaultRevision(TRUE); $revert->setRevisionLogMessage('Revert to captured revision'); $revert->save();
    $this->assertNotContains($revert->getRevisionId(), [$original_revision, $edited_revision]);
    $this->assertSame('Original rationale', $storage->loadUnchanged($trace->id())->get('rationale')->value);
    $this->assertSame('Form edit', $storage->loadRevision($edited_revision)->get('rationale')->value);
    $this->assertSame('Original rationale', $storage->loadRevision($original_revision)->get('rationale')->value);
    $this->assertTrue(\Drupal::service('divengine_process.baselines')->protectsRevision('trace_link', $original_revision));
  }

}
