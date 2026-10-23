<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Kernel;

use Drupal\Component\Datetime\Time;

/**
 * System time that a test sets, so recorded results get distinct times.
 */
class QaTestClock extends Time {

  /**
   * The current and request time, in seconds.
   */
  public static int $now = 1_800_000_000;

  public function getRequestTime() {
    return static::$now;
  }

  public function getRequestMicroTime() {
    return (float) static::$now;
  }

  public function getCurrentTime() {
    return static::$now;
  }

  public function getCurrentMicroTime() {
    return (float) static::$now;
  }

}
