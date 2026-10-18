<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to documentation sets and their snapshots.
 *
 * Everything follows the Development. Snapshots are written by the
 * importer only; ready history is deleted by administrators only.
 */
class CodeDocsAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $development = $entity->get('development')->entity;
    $visible = $development ? $development->access('view', $account, TRUE) : AccessResult::allowedIfHasPermission($account, 'administer code docs');
    if (!$visible->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($visible)->addCacheableDependency($entity)->cachePerPermissions();
    }
    $admin = $account->hasPermission('administer code docs');
    $manage = $admin || $account->hasPermission('manage code docs');
    $read = $manage || $account->hasPermission('use code docs');
    $snapshot = $entity->getEntityTypeId() === 'codedoc_snapshot';
    $allowed = match ($operation) {
      'view', 'view label' => $read && (!$snapshot || $entity->isReady()),
      'update' => $manage && !$snapshot,
      'delete' => $admin || ($manage && ($snapshot ? !$entity->isReady() : !$entity->hasHistory(TRUE))),
      default => FALSE,
    };
    return AccessResult::allowedIf($allowed)->cachePerPermissions()->addCacheableDependency($visible)->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIf($this->entityTypeId !== 'codedoc_snapshot')
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['manage code docs', 'administer code docs'], 'OR'));
  }

}
