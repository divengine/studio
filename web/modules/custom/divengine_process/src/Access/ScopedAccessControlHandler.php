<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to a record that belongs to one Development.
 *
 * Viewing follows the Development. Editing needs the Development operation
 * of the record's type (DevelopmentVisibility::operationFor()): records of
 * the process structure (phases, iterations, milestones, role assignments,
 * baselines) are managed by those who manage the Development; other records
 * by whoever participates in it (#84). Deleting is for Process Managers,
 * except for lightweight records (see $usersDelete). Adding a record to a
 * Development without that operation is refused by the
 * ProcessDevelopmentEditable constraint.
 */
class ScopedAccessControlHandler extends EntityAccessControlHandler {

  /**
   * Whether users who edit the Development may delete these records.
   */
  protected bool $usersDelete = FALSE;

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer unified process')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $development = $entity->get('development')->entity;
    if (!$development) {
      return AccessResult::neutral()->cachePerPermissions()->addCacheableDependency($entity);
    }
    $edit = DevelopmentVisibility::operationFor($entity->getEntityTypeId());
    $result = match ($operation) {
      'view', 'view label', 'view revision', 'view all revisions' => $development->access('view', $account, TRUE),
      'update' => $development->access($edit, $account, TRUE),
      'delete' => $this->usersDelete ? $development->access($edit, $account, TRUE) : AccessResult::neutral()->cachePerPermissions(),
      default => AccessResult::neutral()->cachePerPermissions(),
    };
    return $result->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
  }

}
