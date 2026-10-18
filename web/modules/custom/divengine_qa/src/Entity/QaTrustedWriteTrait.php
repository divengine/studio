<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

/**
 * Marks a save as made by the QA workflow services.
 *
 * The services check the workflow rules themselves (who may start a run,
 * record a result, skip a case…) and then save as trusted; the entity
 * constraints refuse the same changes through any other path (forms,
 * REST, JSON:API).
 */
trait QaTrustedWriteTrait {

  protected bool $trusted = FALSE;

  public function trust(): static {
    $this->trusted = TRUE;
    return $this;
  }

  public function isTrusted(): bool {
    return $this->trusted;
  }

  /**
   * Saves as the workflow and clears the mark.
   */
  public function saveTrusted(): int {
    $this->trusted = TRUE;
    try {
      return $this->save();
    }
    finally {
      $this->trusted = FALSE;
    }
  }

}
