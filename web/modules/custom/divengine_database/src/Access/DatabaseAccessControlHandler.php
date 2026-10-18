<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to databases and their snapshots.
 *
 * Everything follows the Development of the database. Snapshots are written
 * by the importer only; ready history is deleted by administrators only.
 */
class DatabaseAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $development = $entity->get('development')->entity;
    $visible = $development ? $development->access('view', $account, TRUE) : AccessResult::allowedIfHasPermission($account, 'administer database schemas');
    if (!$visible->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($visible)->addCacheableDependency($entity);
    }
    $admin = $account->hasPermission('administer database schemas');
    $manage = $admin || $account->hasPermission('manage database schemas');
    $explore = $manage || $account->hasPermission('use database schemas');
    $snapshot = $entity->getEntityTypeId() === 'schema_snapshot';
    $allowed = match ($operation) {
      'view', 'view label' => $explore,
      'update' => $manage && !$snapshot,
      // Ready history is preserved: only administrators delete it.
      'delete' => $admin || ($manage && ($snapshot ? !$entity->isReady() : !$entity->hasHistory(TRUE))),
      default => FALSE,
    };
    return AccessResult::allowedIf($allowed)->cachePerPermissions()->addCacheableDependency($visible)->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIf($this->entityTypeId !== 'schema_snapshot')
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['manage database schemas', 'administer database schemas'], 'OR'));
  }

}
