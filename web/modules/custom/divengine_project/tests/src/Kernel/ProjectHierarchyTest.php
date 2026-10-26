<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_project\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\Tests\divengine_process\Kernel\ConcurrentWriterTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Parent chains of projects and tasks never close a cycle, whatever the
 * writer and however two moves interleave.
 */
#[RunTestsInSeparateProcesses]
class ProjectHierarchyTest extends ProjectKernelTestBase {

  use ConcurrentWriterTrait;

  public function testValidationReportsTheCycle(): void {
    $owner = $this->worker('owner');
    $top = $this->project('TOP', $owner);
    $child = $this->project('CHILD', $owner, ['parent' => $top->id()]);
    $top->set('parent', $child->id());
    $messages = array_map(fn ($violation) => (string) $violation->getMessage(), iterator_to_array($top->validate()->getByField('parent')));
    $this->assertNotEmpty(array_filter($messages, fn ($message) => str_contains($message, 'cannot be the parent: it is this record or one of its descendants')));
  }

  public function testDirectSavesCannotCloseACycle(): void {
    $owner = $this->worker('owner');
    $top = $this->project('TOP', $owner);
    $child = $this->project('CHILD', $owner, ['parent' => $top->id()]);
    $grandchild = $this->project('GRAND', $owner, ['parent' => $child->id()]);
    $this->assertRefused(Project::load($top->id())->set('parent', $grandchild->id()));
    $this->assertRefused(Project::load($top->id())->set('parent', $top->id()));
    $this->assertNull(Project::load($top->id())->get('parent')->target_id);

    $task = $this->task($top, 'Task');
    $subtask = $this->task($top, 'Subtask', ['parent' => $task->id()]);
    $this->assertRefused(ProjectTask::load($task->id())->set('parent', $subtask->id()));
    $this->assertNull(ProjectTask::load($task->id())->get('parent')->target_id);

    // Moves that keep the chains acyclic are stored.
    $grandchild->set('parent', $top->id())->save();
    $this->assertSame((string) $top->id(), (string) Project::load($grandchild->id())->get('parent')->target_id);
  }

  public function testConcurrentMovesCannotCloseACycle(): void {
    $owner = $this->worker('owner');
    $one = $this->project('ONE', $owner);
    $two = $this->project('TWO', $owner);
    // While this request waits for the hierarchy lock, another one places
    // TWO under ONE and commits.
    \Drupal::lock()->holdUntilWaitedFor('divengine_project_hierarchy:project', function () use ($two, $one): void {
      $this->saveAsAnotherRequest(Project::load($two->id())->set('parent', $one->id()));
    });
    // Each move alone is valid: ONE under TWO is refused only because the
    // other request's move is read from storage once the lock is free.
    $this->assertRefused(Project::load($one->id())->set('parent', $two->id()));
    $this->container->get('entity_type.manager')->getStorage('project')->resetCache();
    $this->assertSame((string) $one->id(), (string) Project::load($two->id())->get('parent')->target_id);
    $this->assertNull(Project::load($one->id())->get('parent')->target_id);
    $this->assertLockReleased('divengine_project_hierarchy:project');
  }

  protected function assertRefused(Project|ProjectTask $entity): void {
    try {
      $entity->save();
      $this->fail('The cycle was stored.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cannot be placed under one of its own descendants', $e->getMessage());
    }
  }

}
