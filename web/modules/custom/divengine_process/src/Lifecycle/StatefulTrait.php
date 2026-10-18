<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

/**
 * Implements the state bookkeeping of StatefulInterface.
 */
trait StatefulTrait {

  protected bool $transitioning = FALSE;

  public function getState(): string {
    return (string) $this->get('state')->value;
  }

  public function setStateThroughTransition(string $state): static {
    $this->transitioning = TRUE;
    $this->set('state', $state);
    return $this;
  }

  public function isTransitioning(): bool {
    return $this->transitioning;
  }

  public function endTransition(): static {
    $this->transitioning = FALSE;
    return $this;
  }

  /**
   * The label of the current state.
   */
  public function getStateLabel(): string {
    return (string) (static::stateLabels()[$this->getState()] ?? $this->getState());
  }

}
