<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Applies lifecycle transitions.
 *
 * A transition is refused when the entity is not in one of its "from"
 * states, when its guard gives a reason, or when the entity does not
 * validate afterwards. Readiness never takes part: it is advisory.
 */
class LifecycleManager {

  use StringTranslationTrait;

  /**
   * Transitions that can leave the current state, keyed by machine name.
   *
   * @return \Drupal\divengine_process\Lifecycle\Transition[]
   */
  public function available(StatefulInterface $entity): array {
    $state = $entity->getState();
    return array_filter($entity::transitions(), fn (Transition $t): bool => in_array($state, $t->from, TRUE));
  }

  /**
   * Applies a transition and saves the entity.
   *
   * @throws \Drupal\divengine_process\Lifecycle\TransitionException
   *   With a message for the user when the transition is refused.
   */
  public function apply(StatefulInterface $entity, string $transition_id): void {
    $transition = $entity::transitions()[$transition_id] ?? NULL;
    if (!$transition) {
      throw new TransitionException((string) $this->t('Unknown transition %transition.', ['%transition' => $transition_id]));
    }
    $from = $entity->getState();
    if (!in_array($from, $transition->from, TRUE)) {
      throw new TransitionException((string) $this->t('@entity cannot move from @from to @to.', [
        '@entity' => $entity->label(),
        '@from' => $entity::stateLabels()[$from] ?? $from,
        '@to' => $entity::stateLabels()[$transition->to] ?? $transition->to,
      ]));
    }
    if ($transition->guard && ($reason = $entity->{$transition->guard}())) {
      throw new TransitionException((string) $reason);
    }

    $entity->setStateThroughTransition($transition->to);
    if ($transition->effect) {
      $entity->{$transition->effect}();
    }
    $violations = $entity->validate();
    if ($violations->count()) {
      $entity->set('state', $from);
      $entity->endTransition();
      throw new TransitionException((string) $violations->get(0)->getMessage());
    }
    if ($entity instanceof RevisionLogInterface) {
      $entity->setNewRevision();
      $entity->setRevisionLogMessage((string) $this->t('@transition: @from → @to.', [
        '@transition' => $transition->label,
        '@from' => $entity::stateLabels()[$from] ?? $from,
        '@to' => $entity::stateLabels()[$transition->to] ?? $transition->to,
      ]));
      $entity->setRevisionUserId((int) \Drupal::currentUser()->id());
      $entity->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    }
    $entity->save();
    $entity->endTransition();
    if ($transition->after) {
      $entity->{$transition->after}();
    }
  }

}
