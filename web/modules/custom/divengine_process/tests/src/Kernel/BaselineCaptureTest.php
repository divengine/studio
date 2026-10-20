<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\BaselineRecorder;
use Drupal\divengine_process\Entity\Baseline;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Entity\Milestone;
use Drupal\divengine_process\Lifecycle\TransitionException;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C04: a capture, and the milestone that triggers it, is one outcome.
 */
#[RunTestsInSeparateProcesses]
class BaselineCaptureTest extends KernelTestBase {

  use BaselineFixtureTrait;
  use ConcurrentWriterTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  protected function setUp(): void {
    parent::setUp();
    $this->installBaselineFixture();
  }

  protected function baselineCount(): int {
    return (int) \Drupal::database()->select('baseline', 'b')->countQuery()->execute()->fetchField();
  }

  protected function itemCount(): int {
    return (int) \Drupal::database()->select(BaselineRecorder::TABLE, 'i')->countQuery()->execute()->fetchField();
  }

  protected function milestone(DevelopmentInterface $development): Milestone {
    $ids = \Drupal::entityQuery('milestone')->accessCheck(FALSE)->condition('development', $development->id())->condition('milestone_type', 'lifecycle_objectives')->execute();
    return Milestone::load(reset($ids));
  }

  /**
   * Replaces the recorder with one that fails after writing everything.
   */
  protected function failingRecorder(): void {
    $this->container->set('divengine_process.baselines', new class(\Drupal::entityTypeManager(), \Drupal::database(), \Drupal::lock()) extends BaselineRecorder {

      protected function capture(DevelopmentInterface $development, string $name, ?Milestone $milestone, string $notes): Baseline {
        parent::capture($development, $name, $milestone, $notes);
        throw new \RuntimeException('Injected failure after the items were written.');
      }

    });
  }

  public function testCountDistinguishesAValidEmptyBaseline(): void {
    $empty = Development::create(['name' => 'Nothing baselined yet']);
    $empty->save();
    $draft = Artifact::create(['type' => 'requirement', 'title' => 'Still a draft', 'development' => $empty->id()]);
    $draft->save();
    $baseline = \Drupal::service('divengine_process.baselines')->take($empty, 'Empty on purpose');
    $this->assertSame(0, Baseline::load($baseline->id())->getItemCount());
    $this->assertSame([], \Drupal::service('divengine_process.baselines')->items($baseline));

    [$full] = $this->baselineFixture();
    $full = Baseline::load($full->id());
    $this->assertSame(4, $full->getItemCount(), 'Three artifacts and one trace.');
    $this->assertCount($full->getItemCount(), \Drupal::service('divengine_process.baselines')->items($full));

    $full->set('item_count', 7);
    $this->expectException(EntityStorageException::class);
    $full->save();
  }

  public function testInjectedFailureLeavesNoBaseline(): void {
    [, , $artifacts] = $this->baselineFixture();
    $development = Development::load($artifacts[0]->get('development')->target_id);
    $baselines = $this->baselineCount();
    $items = $this->itemCount();
    $this->failingRecorder();
    try {
      \Drupal::service('divengine_process.baselines')->take($development, 'Interrupted');
      $this->fail('The capture must fail.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('nothing was stored', $e->getMessage());
    }
    $this->assertSame($baselines, $this->baselineCount(), 'No header without its items.');
    $this->assertSame($items, $this->itemCount(), 'No items without their header.');
    $this->assertLockReleased('divengine_process_baseline:' . $development->id());
  }

  public function testFailedCaptureDoesNotReachTheMilestone(): void {
    $development = Development::create(['name' => 'Reach under failure']);
    $development->save();
    $milestone = $this->milestone($development);
    $this->failingRecorder();
    try {
      \Drupal::service('divengine_process.lifecycle')->apply($milestone, 'reach');
      $this->fail('Reaching must fail with its capture.');
    }
    catch (TransitionException $e) {
      $this->assertStringContainsString('nothing was changed', $e->getMessage());
    }
    $stored = \Drupal::entityTypeManager()->getStorage('milestone')->loadUnchanged($milestone->id());
    $this->assertSame('planned', $stored->getState());
    $this->assertNull($stored->get('date_reached')->value);
    $this->assertSame('planned', $milestone->getState(), 'The object the caller holds is put back too.');
    $this->assertFalse($milestone->isTransitioning());
    $this->assertSame(0, $this->baselineCount());
    $this->assertLockReleased('divengine_process_transition:milestone:' . $milestone->id());
    $this->assertLockReleased('divengine_process_baseline:' . $development->id());
  }

  public function testReachingCapturesOnceAndRetriesAreRefused(): void {
    $development = Development::create(['name' => 'Reach once']);
    $development->save();
    $milestone = $this->milestone($development);
    // What a second request loaded before the first one reached it.
    $stale = \Drupal::entityTypeManager()->getStorage('milestone')->loadUnchanged($milestone->id());
    \Drupal::service('divengine_process.lifecycle')->apply($milestone, 'reach');
    $this->assertSame('reached', \Drupal::entityTypeManager()->getStorage('milestone')->loadUnchanged($milestone->id())->getState());
    $this->assertSame(1, $this->baselineCount());
    $this->assertLockReleased('divengine_process_transition:milestone:' . $milestone->id());
    $this->assertLockReleased('divengine_process_baseline:' . $development->id());
    try {
      \Drupal::service('divengine_process.lifecycle')->apply($stale, 'reach');
      $this->fail('A retried reach must not capture again.');
    }
    catch (TransitionException $e) {
      $this->assertStringContainsString('changed meanwhile', $e->getMessage());
    }
    $this->assertSame(1, $this->baselineCount());
    $this->assertLockReleased('divengine_process_transition:milestone:' . $milestone->id());
  }

  public function testPendingDraftIsNotCaptured(): void {
    $development = Development::create(['name' => 'Drafts stay out']);
    $development->save();
    $approved = Artifact::create(['type' => 'requirement', 'title' => 'Approved wording', 'development' => $development->id(), 'moderation_state' => 'published']);
    $approved->save();
    $published_revision = (int) $approved->getRevisionId();
    $approved->setTitle('Pending wording');
    $approved->set('moderation_state', 'draft');
    $approved->setNewRevision();
    $approved->save();
    $other = Artifact::create(['type' => 'requirement', 'title' => 'Second', 'development' => $development->id(), 'moderation_state' => 'published']);
    $other->save();
    $trace = TraceLink::create(['source_entity_type' => 'artifact', 'source_entity_id' => $approved->id(), 'target_entity_type' => 'artifact', 'target_entity_id' => $other->id(), 'link_type' => 'references']);
    $trace->save();

    $baseline = \Drupal::service('divengine_process.baselines')->take($development, 'Approved only');
    $items = [];
    foreach (\Drupal::service('divengine_process.baselines')->items($baseline) as $item) {
      $items[$item['entity_type'] . ':' . $item['entity_id']] = (int) $item['revision_id'];
    }
    $this->assertSame($published_revision, $items['artifact:' . $approved->id()], 'The approved default revision is captured, not the pending draft.');
    $this->assertSame((int) $trace->getRevisionId(), $items['trace_link:' . $trace->id()]);
    $this->assertSame(3, Baseline::load($baseline->id())->getItemCount());
  }

  public function testCaptureWaitsForAnotherCaptureOfTheSameDevelopment(): void {
    $development = Development::create(['name' => 'Serialized captures']);
    $development->save();
    $other_request = new DatabaseLockBackend(\Drupal::database());
    $this->assertTrue($other_request->acquire('divengine_process_baseline:' . $development->id(), 60));
    $impatient = new class(\Drupal::entityTypeManager(), \Drupal::database(), \Drupal::lock()) extends BaselineRecorder {
      const LOCK_WAIT = 1;
    };
    try {
      $impatient->take($development, 'Concurrent');
      $this->fail('A capture must not run while another one holds the Development.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('being taken', $e->getMessage());
    }
    $this->assertSame(0, $this->baselineCount());
    $other_request->release('divengine_process_baseline:' . $development->id());
    $impatient->take($development, 'After the other one');
    $this->assertSame(1, $this->baselineCount());
  }

  public function testConcurrentReachCapturesOnce(): void {
    $development = Development::create(['name' => 'Two requests reach together']);
    $development->save();
    $milestone = $this->milestone($development);
    $id = (int) $milestone->id();
    // Another request reaches the milestone while this one waits for it.
    \Drupal::lock()->holdUntilWaitedFor('divengine_process_transition:milestone:' . $id, function () use ($id) {
      $other = \Drupal::entityTypeManager()->getStorage('milestone')->loadUnchanged($id);
      \Drupal::service('divengine_process.lifecycle')->apply($other, 'reach');
    });
    try {
      \Drupal::service('divengine_process.lifecycle')->apply($milestone, 'reach');
      $this->fail('The second reach must be refused.');
    }
    catch (TransitionException $e) {
      $this->assertStringContainsString('changed meanwhile', $e->getMessage());
    }
    $this->assertSame(1, $this->baselineCount());
    $this->assertSame('reached', \Drupal::entityTypeManager()->getStorage('milestone')->loadUnchanged($id)->getState());
    $this->assertLockReleased('divengine_process_transition:milestone:' . $id);
    $this->assertLockReleased('divengine_process_baseline:' . $development->id());
  }

}
