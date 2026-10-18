<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * An entity whose "state" field only changes through transitions.
 */
interface StatefulInterface extends ContentEntityInterface {

  /**
   * The transitions of the lifecycle, keyed by machine name.
   *
   * @return \Drupal\divengine_process\Lifecycle\Transition[]
   */
  public static function transitions(): array;

  /**
   * The labels of the states, keyed by state.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function stateLabels(): array;

  public function getState(): string;

  /**
   * Sets the state as the outcome of a transition.
   *
   * Only the lifecycle manager calls this.
   */
  public function setStateThroughTransition(string $state): static;

  /**
   * Whether the pending state change comes from a transition.
   */
  public function isTransitioning(): bool;

  /**
   * Clears the transition flag once the entity is saved.
   */
  public function endTransition(): static;

}
