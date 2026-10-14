<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for artifacts.
 *
 * Every operation is granted by the type-wide permission or by the permission
 * of the artifact's bundle (see ArtifactPermissions).
 */
class ArtifactAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $bundle = $entity->bundle();
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions($account, ['view artifact', 'administer divengine'], 'OR'),
      'update' => $this->anyOf($account, 'edit artifact', "edit $bundle artifact"),
      'delete' => $this->anyOf($account, 'delete artifact', "delete $bundle artifact"),
      'view revision', 'view all revisions' => $this->anyOf($account, 'view artifact revisions', "view $bundle artifact revisions"),
      'revert' => $this->anyOf($account, 'revert artifact revisions', "revert $bundle artifact revisions"),
      'delete revision' => $this->anyOf($account, 'delete artifact revisions', "delete $bundle artifact revisions"),
      default => AccessResult::neutral(),
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $permissions = ['create artifact', 'administer divengine'];
    if ($entity_bundle) {
      $permissions[] = "create $entity_bundle artifact";
    }
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

  /**
   * Allows the operation through the global, bundle or admin permission.
   */
  protected function anyOf(AccountInterface $account, string $global, string $bundle): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, [$global, $bundle, 'administer divengine'], 'OR');
  }

}
