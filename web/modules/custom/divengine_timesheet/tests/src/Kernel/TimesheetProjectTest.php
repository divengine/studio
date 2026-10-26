<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_timesheet\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_timesheet\Entity\Timesheet;
use Drupal\Tests\divengine_project\Kernel\ProjectKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An entry on a task belongs to the task's project; a contradiction is refused.
 *
 * The rule holds for writers that validate (forms, JSON:API) and for direct
 * saves. A project is never silently replaced.
 */
#[RunTestsInSeparateProcesses]
class TimesheetProjectTest extends ProjectKernelTestBase {

  protected static $modules = ['divengine_timesheet'];

  protected UserInterface $user;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('timesheet');
    $this->user = $this->worker('worker');
    $this->setCurrentUser($this->user);
  }

  public function testMatchingProjectIsStored(): void {
    [$one, , $task] = $this->fixture();
    $entry = $this->entry(['task' => $task->id(), 'project' => $one->id()]);
    $this->assertCount(0, $entry->validate());
    $entry->save();
    $this->assertSame((string) $one->id(), (string) $this->reload($entry)->get('project')->target_id);
  }

  public function testTaskOnlyTakesTheTaskProject(): void {
    [$one, , $task] = $this->fixture();
    $entry = $this->entry(['task' => $task->id()]);
    $this->assertCount(0, $entry->validate(), 'A task without a project is valid.');
    $entry->save();
    $this->assertSame((string) $one->id(), (string) $this->reload($entry)->get('project')->target_id);
  }

  public function testContradictingProjectIsRefused(): void {
    [$one, $two, $task] = $this->fixture();
    $entry = $this->entry(['task' => $task->id(), 'project' => $two->id()]);
    $violations = $entry->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('project', $violations[0]->getPropertyPath());
    $this->assertStringContainsString('Choose ' . $one->label(), strip_tags((string) $violations[0]->getMessage()));

    try {
      $entry->save();
      $this->fail('A direct save with a contradicting project is refused.');
    }
    catch (EntityStorageException) {
    }
    $this->assertSame([], $this->stored(), 'Nothing is stored, and the project was not replaced.');

    // A retry with the same values is refused again.
    $this->expectException(EntityStorageException::class);
    $this->entry(['task' => $task->id(), 'project' => $two->id()])->save();
  }

  public function testChangingTheTaskOrProjectIsChecked(): void {
    [$one, $two, $task] = $this->fixture();
    $other = $this->task($two, 'Other');
    $entry = $this->entry(['task' => $task->id(), 'project' => $one->id()]);
    $entry->save();

    $entry->set('task', $other->id());
    $this->assertCount(1, $entry->validate(), 'Moving the entry to a task of another project needs that project.');
    $entry->set('project', $two->id());
    $this->assertCount(0, $entry->validate());
    $entry->save();

    $entry->set('project', $one->id());
    $this->expectException(EntityStorageException::class);
    $entry->save();
  }

  public function testEntriesOfAMovedTaskStayEditable(): void {
    [$one, $two, $task] = $this->fixture();
    $entry = $this->entry(['task' => $task->id()]);
    $entry->save();
    // The task moves on; the time already logged stays on the old project.
    $task->set('project', $two->id())->save();

    $entry = $this->reload($entry);
    $entry->set('hours', 3);
    $this->assertCount(0, $entry->validate());
    $entry->save();
    $entry = $this->reload($entry);
    $this->assertSame((string) $one->id(), (string) $entry->get('project')->target_id);
    $this->assertSame(3.0, $entry->getHours());
  }

  public function testProjectOrTaskIsRequired(): void {
    $this->fixture();
    $entry = $this->entry([]);
    $violations = $entry->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('project', $violations[0]->getPropertyPath());
    $this->expectException(EntityStorageException::class);
    $entry->save();
  }

  /**
   * Two projects of the current user and a task of the first.
   *
   * @return array{0: \Drupal\divengine_project\Entity\Project, 1: \Drupal\divengine_project\Entity\Project, 2: \Drupal\divengine_project\Entity\ProjectTask}
   */
  protected function fixture(): array {
    $one = $this->project('ONE', $this->user);
    $two = $this->project('TWO', $this->user);
    return [$one, $two, $this->task($one, 'Task')];
  }

  protected function entry(array $values): Timesheet {
    return Timesheet::create($values + [
      'uid' => $this->user->id(),
      'date' => '2026-10-19',
      'hours' => 2,
    ]);
  }

  protected function reload(Timesheet $entry): Timesheet {
    return \Drupal::entityTypeManager()->getStorage('timesheet')->loadUnchanged($entry->id());
  }

  /**
   * IDs of the stored entries.
   */
  protected function stored(): array {
    return array_keys(\Drupal::entityTypeManager()->getStorage('timesheet')->loadMultiple());
  }

}
