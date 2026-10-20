<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Applies lifecycle transitions.
 *
 * A transition is refused when the entity is not in one of its "from"
 * states, when its guard gives a reason, or when the entity does not
 * validate afterwards. Readiness never takes part: it is advisory.
 *
 * Applying is one outcome: the save and the after-save effect (the baseline
 * of a reached milestone) commit together or not at all, and transitions of
 * the same record are serialized, so a retried or concurrent request finds
 * the state the first one left. Who may apply a transition is decided by
 * the caller.
 */
class LifecycleManager {

  use StringTranslationTrait;

  /**
   * Seconds to wait for another transition of the same record.
   */
  const LOCK_WAIT = 30;

  public function __construct(
    protected Connection $database,
    protected LockBackendInterface $lock,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
   * The URL that applies a transition from a button.
   *
   * Transitions that ask for confirmation or for a value open their form;
   * the others apply in one click. After applying, the user goes back to
   * $return.
   */
  public function url(StatefulInterface $entity, string $transition_id, ?Url $return = NULL): Url {
    $transition = $entity::transitions()[$transition_id];
    $route = $transition->confirm || $transition->field ? 'divengine_process.transition_confirm' : 'divengine_process.transition';
    return Url::fromRoute($route, [
      'entity_type' => $entity->getEntityTypeId(),
      'entity' => $entity->id(),
      'transition' => $transition_id,
    ], $return ? ['query' => ['destination' => $return->toString()]] : []);
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
    $lock = 'divengine_process_transition:' . $entity->getEntityTypeId() . ':' . $entity->id();
    if (!$this->acquire($lock)) {
      throw new TransitionException((string) $this->t('@entity is being changed by another request. Try again.', ['@entity' => $entity->label()]));
    }
    $this->applyLocked($entity, $transition, $lock);
  }

  /**
   * Applies a transition while holding the lock of the record.
   *
   * The lock is released when the transaction that stores the outcome ends,
   * or right away when the transition is refused before writing.
   */
  protected function applyLocked(StatefulInterface $entity, Transition $transition, string $lock): void {
    try {
      $this->check($entity, $transition);
    }
    catch (\Throwable $e) {
      $this->lock->release($lock);
      throw $e;
    }
    $from = $entity->getState();
    $stored = $entity->isNew() ? NULL : $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    $transaction = $this->database->startTransaction();
    $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->lock->release($lock));
    try {
      $entity->setStateThroughTransition($transition->to);
      if ($transition->effect) {
        $entity->{$transition->effect}();
      }
      $violations = $entity->validate();
      if ($violations->count()) {
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
      if ($transition->after) {
        $entity->{$transition->after}();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->restore($entity, $stored);
      if ($e instanceof TransitionException) {
        throw $e;
      }
      throw new TransitionException((string) $this->t('@transition could not be applied to @entity; nothing was changed. @reason', [
        '@transition' => $transition->label,
        '@entity' => $entity->label(),
        '@reason' => $e->getMessage(),
      ]), 0, $e);
    }
    finally {
      $entity->endTransition();
    }
    // Commits the save and its after-save effect together.
    unset($transaction);
  }

  /**
   * Refuses a transition that cannot apply to the stored record.
   */
  protected function check(StatefulInterface $entity, Transition $transition): void {
    $from = $entity->getState();
    // A concurrent or retried request may have moved the record meanwhile.
    $stored = $entity->isNew() ? NULL : $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($stored instanceof StatefulInterface && $stored->getState() !== $from) {
      throw new TransitionException((string) $this->t('@entity was changed meanwhile: it is now @state.', [
        '@entity' => $entity->label(),
        '@state' => $entity::stateLabels()[$stored->getState()] ?? $stored->getState(),
      ]));
    }
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
  }

  /**
   * Puts a refused or failed entity back to what is stored.
   */
  protected function restore(StatefulInterface $entity, ?StatefulInterface $stored): void {
    $entity->endTransition();
    if (!$stored) {
      return;
    }
    foreach ($stored->getFields() as $name => $field) {
      $entity->set($name, $field->getValue());
    }
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(FALSE);
      $entity->updateLoadedRevisionId();
    }
  }

  protected function acquire(string $lock): bool {
    $deadline = time() + static::LOCK_WAIT;
    while (!$this->lock->acquire($lock)) {
      if (time() >= $deadline) {
        return FALSE;
      }
      $this->lock->wait($lock, 5);
    }
    return TRUE;
  }

}
