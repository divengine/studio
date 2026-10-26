<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserDataInterface;

/**
 * One running timer per person, on a task.
 *
 * Stopping it turns the elapsed time into hours, rounded up to the next
 * quarter of an hour (at least 15 minutes), ready to be logged.
 *
 * Starting and stopping are serialized per person, so two requests (a
 * double click, two tabs) never both take the same running timer: only
 * one of them gets its time back.
 */
class Timer {

  const MINUTES = 15;

  public function __construct(
    protected UserDataInterface $userData,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * The running timer: ['task' => id, 'start' => timestamp], or NULL.
   */
  public function current(AccountInterface $account): ?array {
    $timer = $this->userData->get('divengine_timesheet', (int) $account->id(), 'timer');
    return is_array($timer) ? $timer : NULL;
  }

  /**
   * Starts the timer on a task.
   *
   * Starting it again on the task it runs on keeps it running from its
   * start. A timer running on another task is stopped.
   *
   * @return array|null
   *   The timer stopped on another task, for its time to be logged, or NULL.
   */
  public function start(AccountInterface $account, int $task_id): ?array {
    return $this->locked($account, function () use ($account, $task_id): ?array {
      $running = $this->current($account);
      if ($running && (int) $running['task'] === $task_id) {
        return NULL;
      }
      $this->userData->set('divengine_timesheet', (int) $account->id(), 'timer', ['task' => $task_id, 'start' => $this->time->getRequestTime()]);
      return $running;
    });
  }

  /**
   * Stops the timer and returns the hours to log (0 when none was running).
   */
  public function stop(AccountInterface $account): float {
    $timer = $this->locked($account, function () use ($account): ?array {
      $timer = $this->current($account);
      $this->userData->delete('divengine_timesheet', (int) $account->id(), 'timer');
      return $timer;
    });
    return $timer ? $this->hours($timer) : 0.0;
  }

  /**
   * Runs a change of a person's timer while holding their timer lock.
   */
  protected function locked(AccountInterface $account, callable $change): ?array {
    $lock = 'divengine_timesheet_timer:' . (int) $account->id();
    $deadline = time() + 30;
    while (!$this->lock->acquire($lock)) {
      if (time() >= $deadline) {
        throw new \RuntimeException('Another request is changing this timer. Try again.');
      }
      $this->lock->wait($lock, 5);
    }
    try {
      return $change();
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Elapsed time of a timer in hours, rounded up to the quarter hour.
   */
  public function hours(array $timer): float {
    $minutes = max(1, (int) ceil(($this->time->getRequestTime() - (int) $timer['start']) / 60));
    return ceil($minutes / static::MINUTES) * static::MINUTES / 60;
  }

}
