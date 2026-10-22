<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_timesheet\Kernel;

use Drupal\Tests\divengine_process\Kernel\ConcurrentWriterTrait;
use Drupal\Tests\divengine_project\Kernel\ProjectKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * One running timer per person: a retry keeps it, and two requests never
 * both take the same running timer.
 */
#[RunTestsInSeparateProcesses]
class TimerTest extends ProjectKernelTestBase {

  use ConcurrentWriterTrait;

  protected static $modules = ['divengine_timesheet'];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
  }

  public function testRetryKeepsTheRunningTimer(): void {
    [$user, $timer, $a] = $this->fixture();
    $this->assertNull($timer->start($user, (int) $a->id()));
    // The timer has been running for ten minutes.
    $started = ['task' => (int) $a->id(), 'start' => $timer->current($user)['start'] - 600];
    $this->container->get('user.data')->set('divengine_timesheet', (int) $user->id(), 'timer', $started);

    // A second start on the same task (a double click, a reload) neither
    // restarts the timer nor reports a timer to log.
    $this->assertNull($timer->start($user, (int) $a->id()));
    $this->assertSame($started, $timer->current($user));
  }

  public function testSwitchingReturnsThePreviousTimerOnce(): void {
    [$user, $timer, $a, $b] = $this->fixture();
    $timer->start($user, (int) $a->id());
    $stopped = $timer->start($user, (int) $b->id());
    $this->assertSame((int) $a->id(), $stopped['task']);
    $this->assertSame((int) $b->id(), $timer->current($user)['task']);
    $this->assertGreaterThan(0.0, $timer->stop($user));
    $this->assertSame(0.0, $timer->stop($user), 'A second stop has nothing to log.');
    $this->assertNull($timer->current($user));
  }

  public function testConcurrentStartsDoNotBothTakeTheRunningTimer(): void {
    [$user, $timer, $a, $b, $c] = $this->fixture();
    $timer->start($user, (int) $a->id());
    // While this request waits for the timer lock, another one switches the
    // timer from A to B.
    $other = NULL;
    \Drupal::lock()->holdUntilWaitedFor('divengine_timesheet_timer:', function () use (&$other, $timer, $user, $b): void {
      $other = $timer->start($user, (int) $b->id());
    });
    $mine = $timer->start($user, (int) $c->id());
    $this->assertSame((int) $a->id(), $other['task'], 'The other request logs the timer of A.');
    $this->assertSame((int) $b->id(), $mine['task'], 'This request logs the timer of B, not A again.');
    $this->assertSame((int) $c->id(), $timer->current($user)['task']);
    $this->assertLockReleased('divengine_timesheet_timer:' . $user->id());
  }

  public function testConcurrentStopsReturnTheTimeOnce(): void {
    [$user, $timer, $a] = $this->fixture();
    $timer->start($user, (int) $a->id());
    $other = NULL;
    \Drupal::lock()->holdUntilWaitedFor('divengine_timesheet_timer:', function () use (&$other, $timer, $user): void {
      $other = $timer->stop($user);
    });
    $this->assertSame(0.0, $timer->stop($user));
    $this->assertSame(0.25, $other);
  }

  /**
   * A worker, the timer and three tasks of their project.
   */
  protected function fixture(): array {
    $user = $this->worker('worker');
    $project = $this->project('TIME', $user);
    return [
      $user,
      $this->container->get('divengine_timesheet.timer'),
      $this->task($project, 'A'),
      $this->task($project, 'B'),
      $this->task($project, 'C'),
    ];
  }

}
