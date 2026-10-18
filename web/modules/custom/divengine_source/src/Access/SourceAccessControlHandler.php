<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access to repositories, refs and snapshots.
 *
 * Everything follows the Development of the repository: people who see the
 * Development and may browse code see it; people who manage source code
 * register and index. Snapshots are written by the indexer only.
 */
class SourceAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $development = $entity->get('development')->entity;
    $visible = $development ? $development->access('view', $account, TRUE) : AccessResult::allowedIfHasPermission($account, 'administer source code');
    if (!$visible->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($visible)->addCacheableDependency($entity);
    }
    $browse = $account->hasPermission('use source code') || $account->hasPermission('manage source code') || $account->hasPermission('administer source code');
    $manage = $account->hasPermission('manage source code') || $account->hasPermission('administer source code');
    $allowed = match ($operation) {
      'view', 'view label' => $browse,
      'update', 'delete' => $manage && $entity->getEntityTypeId() !== 'source_snapshot',
      default => FALSE,
    };
    return AccessResult::allowedIf($allowed)->cachePerPermissions()->addCacheableDependency($visible)->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIf($this->entityTypeId !== 'source_snapshot')
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['manage source code', 'administer source code'], 'OR'));
  }

}
