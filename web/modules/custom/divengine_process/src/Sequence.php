<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Global counters behind codes such as DEV-0001 or REQ-0042.
 *
 * One counter per prefix, never per Development. A lock serializes concurrent
 * requests; the code fields are also unique, so a collision cannot be saved.
 */
class Sequence {

  public function __construct(
    protected StateInterface $state,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Returns the next code for a prefix, e.g. "DEV-0001".
   */
  public function next(string $prefix): string {
    $lock = 'divengine_process_sequence:' . $prefix;
    while (!$this->lock->acquire($lock)) {
      $this->lock->wait($lock, 5);
    }
    try {
      $key = 'divengine_process.sequence.' . $prefix;
      // Another process may have advanced the counter since it was cached.
      $this->state->resetCache();
      $value = (int) $this->state->get($key, 0) + 1;
      $this->state->set($key, $value);
    }
    finally {
      $this->lock->release($lock);
    }
    return sprintf('%s-%04d', $prefix, $value);
  }

}
