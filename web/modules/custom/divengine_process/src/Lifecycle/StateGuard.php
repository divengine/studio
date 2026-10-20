<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

use Drupal\Core\Entity\EntityStorageException;

/**
 * Persistence invariant of the Unified Process lifecycles.
 *
 * Whatever the writer, a Development, iteration, milestone, activity or
 * change request is stored only in one of its states, and its state changes
 * only as the outcome of one of its declared transitions. Who may apply a
 * transition is decided by the caller (controllers check access); this guard
 * only refuses states and moves that the lifecycle does not have.
 */
final class StateGuard {

  /**
   * Entity types whose lifecycle the guard enforces.
   */
  const ENTITY_TYPES = ['development', 'iteration', 'milestone', 'process_activity', 'change_request'];

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When the state, or the change of state, is not part of the lifecycle.
   */
  public static function assertPersistable(StatefulInterface $entity): void {
    if (!in_array($entity->getEntityTypeId(), static::ENTITY_TYPES, TRUE)) {
      return;
    }
    $state = $entity->getState();
    if (!array_key_exists($state, $entity::stateLabels())) {
      throw new EntityStorageException(sprintf('%s cannot be stored in the unknown state "%s".', $entity->getEntityTypeId(), $state));
    }
    $original = $entity->isNew() ? NULL : $entity->getOriginal();
    if (!$original instanceof StatefulInterface || $original->getState() === $state) {
      return;
    }
    $from = $original->getState();
    if (!$entity->isTransitioning()) {
      throw new EntityStorageException(sprintf('The state of %s %s changes only through its transitions (%s to %s was saved directly).', $entity->getEntityTypeId(), $entity->id(), $from, $state));
    }
    foreach ($entity::transitions() as $transition) {
      if ($transition->to === $state && in_array($from, $transition->from, TRUE)) {
        return;
      }
    }
    throw new EntityStorageException(sprintf('No transition of %s moves from %s to %s.', $entity->getEntityTypeId(), $from, $state));
  }

}
