<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;

/**
 * Database locks where another request can be made to hold a lock.
 *
 * While a name is held, acquiring it fails; the first wait for it runs the
 * other request's work, after which the lock is free again. This reproduces
 * the interleaving of two requests deterministically in one process.
 */
class ScriptedLockBackend extends DatabaseLockBackend {

  /**
   * Work of the other request, keyed by the lock name prefix it holds.
   *
   * @var callable[]
   */
  protected array $holders = [];

  public function holdUntilWaitedFor(string $prefix, callable $work): void {
    $this->holders[$prefix] = $work;
  }

  public function acquire($name, $timeout = 30.0) {
    return $this->holder($name) ? FALSE : parent::acquire($name, $timeout);
  }

  public function wait($name, $delay = 30) {
    if ($prefix = $this->holder($name)) {
      $work = $this->holders[$prefix];
      unset($this->holders[$prefix]);
      $work();
      return FALSE;
    }
    return parent::wait($name, $delay);
  }

  protected function holder(string $name): ?string {
    foreach (array_keys($this->holders) as $prefix) {
      if (str_starts_with($name, $prefix)) {
        return $prefix;
      }
    }
    return NULL;
  }

}
