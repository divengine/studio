<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to Developments.
 *
 * Whoever sees a Development may work on it; only Process Managers delete
 * Developments or their revisions.
 */
class DevelopmentAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\divengine_process\Entity\DevelopmentInterface $entity */
    $manager = AccessResult::allowedIfHasPermission($account, 'administer unified process');
    return match ($operation) {
      'view', 'view label', 'update', 'view revision', 'view all revisions' => \Drupal::service('divengine_process.visibility')->access($entity, $account),
      default => $manager,
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
  }

}
