<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_timesheet\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_timesheet\Form\TimesheetGridForm;
use Drupal\Tests\divengine_process\Kernel\ConcurrentWriterTrait;
use Drupal\Tests\divengine_project\Kernel\ProjectKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Saving the weekly grid again, or twice at once, records the hours once.
 */
#[RunTestsInSeparateProcesses]
class TimesheetGridTest extends ProjectKernelTestBase {

  use ConcurrentWriterTrait;

  protected static $modules = ['divengine_timesheet'];

  const DAY = '2026-10-19';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('timesheet');
    Role::load('worker')->grantPermission('log time')->save();
  }

  public function testRetryRecordsNothingMore(): void {
    $task = $this->fixture();
    $this->submit($task, '2');
    $this->submit($task, '2');
    $this->assertSame([2.0], $this->hours());

    // Changing the cell changes the entry; emptying it removes the entry.
    $this->submit($task, '3:30');
    $this->assertSame([3.5], $this->hours());
    $this->submit($task, '');
    $this->assertSame([], $this->hours());
  }

  public function testConcurrentSubmissionsRecordTheHoursOnce(): void {
    $task = $this->fixture();
    // While this submission waits for the grid lock, the same one sent from
    // another tab is saved.
    $other = FALSE;
    \Drupal::lock()->holdUntilWaitedFor('divengine_timesheet_grid:', function () use ($task, &$other): void {
      $this->submit($task, '2');
      $other = TRUE;
    });
    $this->submit($task, '2');
    $this->assertTrue($other, 'The other submission was saved while this one waited.');
    $this->assertSame([2.0], $this->hours());
    $this->assertLockReleased('divengine_timesheet_grid:' . \Drupal::currentUser()->id());
  }

  protected function fixture(): ProjectTask {
    $user = $this->worker('worker');
    $this->setCurrentUser($user);
    return $this->task($this->project('GRID', $user), 'Task');
  }

  /**
   * Submits the grid with one cell for the task on DAY.
   */
  protected function submit(ProjectTask $task, string $hours): void {
    $form_state = (new FormState())
      ->set('days', [static::DAY])
      ->set('rows', ['t' . $task->id() => ['task' => $task->id(), 'project_id' => $task->get('project')->target_id, 'label' => $task->label()]])
      ->setValue(['grid', 't' . $task->id(), static::DAY], $hours);
    $form = [];
    TimesheetGridForm::create($this->container)->submitForm($form, $form_state);
  }

  /**
   * Hours of the stored entries.
   *
   * @return float[]
   */
  protected function hours(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('timesheet');
    $storage->resetCache();
    return array_values(array_map(fn ($entry) => $entry->getHours(), $storage->loadMultiple()));
  }

}
