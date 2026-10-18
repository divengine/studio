<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_qa\QaDeletion;
use Drupal\divengine_qa\QaPermissions;

/**
 * Access to QA records.
 *
 * Every record follows its Development: nobody reaches the QA records of a
 * Development they do not see. On top of that:
 * - design (plans, suites, cases, steps): QA users read, Leads edit,
 *   Managers delete;
 * - runs and their executions, step results and corrections: Leads see
 *   all; anyone else only those of runs assigned to them. Runs are edited
 *   by Leads; Testers act on their runs through the QA services, which
 *   check their own rules.
 * Deleting historical evidence is refused by the entities themselves.
 */
class QaAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Entity types that belong to a run.
   */
  const RUNTIME = ['qa_test_run', 'qa_test_execution', 'qa_step_result', 'qa_result_correction'];

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $development = $entity->get('development')->entity;
    if (!$development) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }
    $visible = $development->access('view', $account, TRUE);
    if (!$visible->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($visible)->addCacheableDependency($entity);
    }
    $result = in_array($entity->getEntityTypeId(), static::RUNTIME, TRUE)
      ? $this->runtimeAccess($entity, $operation, $account)
      : $this->designAccess($operation, $account);
    if ($operation === 'delete' && $result->isAllowed() && ($reason = QaDeletion::refusal($entity))) {
      $result = AccessResult::forbidden((string) $reason)->setCacheMaxAge(0);
    }
    return $result->andIf($visible)->addCacheableDependency($entity)->cachePerPermissions();
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIf(
      !in_array($this->entityTypeId, static::RUNTIME, TRUE) && QaPermissions::isLead($account)
      || $this->entityTypeId === 'qa_test_run' && QaPermissions::isLead($account)
    )->cachePerPermissions();
  }

  protected function designAccess(string $operation, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(match ($operation) {
      'view', 'view label' => QaPermissions::isUser($account),
      'update' => QaPermissions::isLead($account),
      'delete' => QaPermissions::isManager($account),
      default => FALSE,
    });
  }

  protected function runtimeAccess(FieldableEntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $run = $entity->getEntityTypeId() === 'qa_test_run' ? $entity : $entity->get('run')->entity;
    $assigned = $run && (int) $run->get('tester')->target_id === (int) $account->id() && $account->isAuthenticated();
    $lead = QaPermissions::isLead($account);
    $allowed = match ($operation) {
      'view', 'view label' => $lead || ($assigned && QaPermissions::isUser($account)),
      'update' => $entity->getEntityTypeId() === 'qa_test_run'
        ? $lead
        : $entity->getEntityTypeId() !== 'qa_result_correction' && ($lead || ($assigned && QaPermissions::isTester($account))),
      'delete' => QaPermissions::isManager($account) && $entity->getEntityTypeId() !== 'qa_result_correction',
      default => FALSE,
    };
    $result = AccessResult::allowedIf($allowed)->cachePerUser();
    if ($run) {
      $result->addCacheableDependency($run);
    }
    return $result;
  }

}
