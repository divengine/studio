<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\Access\ScopedAccessControlHandler;

/**
 * Access to external references.
 *
 * Seeing one follows its Development. Registering, editing and deleting one
 * also needs "Link external work" (or administering external references),
 * and editing the Development. A reference that trace links still use
 * cannot be deleted.
 */
class ExternalReferenceAccessControlHandler extends ScopedAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $result = parent::checkAccess($entity, $operation, $account);
    if (in_array($operation, ['view', 'view label', 'view revision', 'view all revisions'], TRUE)) {
      return $result;
    }
    /** @var \Drupal\divengine_external\Entity\ExternalReference $entity */
    $development = $entity->get('development')->entity;
    $writer = AccessResult::allowedIfHasPermissions($account, ['link external work', 'administer external references'], 'OR');
    $edits = $development ? $development->access('update', $account, TRUE) : AccessResult::neutral();
    $result = $writer->andIf($edits)->orIf(AccessResult::allowedIfHasPermission($account, 'administer external references'));
    if ($operation === 'delete' && \Drupal::service('divengine_external.references')->traceCount($entity)) {
      return AccessResult::forbidden('Trace links still use this reference.')->addCacheTags(['trace_link_list'])->addCacheableDependency($entity);
    }
    if (!in_array($operation, ['update', 'delete', 'revert'], TRUE)) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    return $result->addCacheableDependency($entity);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'use unified process')
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['link external work', 'administer external references'], 'OR'))
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer external references'));
  }

}
