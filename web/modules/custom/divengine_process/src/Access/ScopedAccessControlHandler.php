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
 * Viewing and editing follow the Development. Deleting is for Process
 * Managers, except for lightweight records (see $usersDelete), which whoever
 * edits the Development may delete. Adding a record to a Development one
 * cannot edit is refused by the ProcessDevelopmentEditable constraint.
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
    $result = match ($operation) {
      'view', 'view label', 'view revision', 'view all revisions' => $development->access('view', $account, TRUE),
      'update' => $development->access('update', $account, TRUE),
      'delete' => $this->usersDelete ? $development->access('update', $account, TRUE) : AccessResult::neutral()->cachePerPermissions(),
      default => AccessResult::neutral()->cachePerPermissions(),
    };
    return $result->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
  }

}
