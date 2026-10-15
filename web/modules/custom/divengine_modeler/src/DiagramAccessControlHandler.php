<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for diagrams.
 *
 * Editing and deleting have "own" and "any" variants, each type-wide or per
 * diagram type (see DiagramPermissions).
 */
class DiagramAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\divengine_modeler\Entity\DiagramInterface $entity */
    $bundle = $entity->bundle();
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions($account, ['view diagrams', 'administer diagrams'], 'OR'),
      'update' => $this->ownOrAny($entity, $account, 'edit'),
      'delete' => $this->ownOrAny($entity, $account, 'delete'),
      'view revision', 'view all revisions' => AccessResult::allowedIfHasPermissions($account, ['view diagram revisions', 'administer diagrams'], 'OR'),
      'revert' => AccessResult::allowedIfHasPermissions($account, ['revert diagram revisions', 'administer diagrams'], 'OR')
        ->andIf($this->ownOrAny($entity, $account, 'edit')),
      'delete revision' => AccessResult::allowedIfHasPermissions($account, ['delete diagram revisions', 'administer diagrams'], 'OR')
        ->andIf($this->ownOrAny($entity, $account, 'delete')),
      default => AccessResult::neutral(),
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    $permissions = ['create diagrams', 'administer diagrams'];
    if ($entity_bundle) {
      $permissions[] = "create $entity_bundle diagram";
    }
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

  /**
   * Grants "edit" or "delete" through the any/own, global/bundle permissions.
   */
  protected function ownOrAny(EntityInterface $entity, AccountInterface $account, string $verb): AccessResultInterface {
    /** @var \Drupal\divengine_modeler\Entity\DiagramInterface $entity */
    $bundle = $entity->bundle();
    $any = AccessResult::allowedIfHasPermissions($account, [
      'administer diagrams',
      $verb === 'edit' ? 'edit any diagram' : 'delete any diagram',
      "$verb any $bundle diagram",
    ], 'OR');
    if ($any->isAllowed()) {
      return $any;
    }
    $own = AccessResult::allowedIfHasPermissions($account, [
      "$verb own diagrams",
      "$verb own $bundle diagram",
    ], 'OR');
    $is_owner = (int) $entity->getOwnerId() === (int) $account->id() && $account->isAuthenticated();
    return $any->orIf($own->andIf(AccessResult::allowedIf($is_owner)->addCacheableDependency($entity)->cachePerUser()));
  }

}
