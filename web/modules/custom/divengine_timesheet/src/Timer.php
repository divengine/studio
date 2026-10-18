<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserDataInterface;

/**
 * One running timer per person, on a task.
 *
 * Stopping it turns the elapsed time into hours, rounded up to the next
 * quarter of an hour (at least 15 minutes), ready to be logged.
 */
class Timer {

  const MINUTES = 15;

  public function __construct(
    protected UserDataInterface $userData,
    protected TimeInterface $time,
  ) {}

  /**
   * The running timer: ['task' => id, 'start' => timestamp], or NULL.
   */
  public function current(AccountInterface $account): ?array {
    $timer = $this->userData->get('divengine_timesheet', (int) $account->id(), 'timer');
    return is_array($timer) ? $timer : NULL;
  }

  public function start(AccountInterface $account, int $task_id): void {
    $this->userData->set('divengine_timesheet', (int) $account->id(), 'timer', ['task' => $task_id, 'start' => $this->time->getRequestTime()]);
  }

  /**
   * Stops the timer and returns the hours to log (0 when none was running).
   */
  public function stop(AccountInterface $account): float {
    $timer = $this->current($account);
    $this->userData->delete('divengine_timesheet', (int) $account->id(), 'timer');
    return $timer ? $this->hours($timer) : 0.0;
  }

  /**
   * Elapsed time of a timer in hours, rounded up to the quarter hour.
   */
  public function hours(array $timer): float {
    $minutes = max(1, (int) ceil(($this->time->getRequestTime() - (int) $timer['start']) / 60));
    return ceil($minutes / static::MINUTES) * static::MINUTES / 60;
  }

}
