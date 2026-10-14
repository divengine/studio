<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

class ArtifactAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin = 'administer divengine';

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions($account, ['view artifact', $admin], 'OR'),
      'update' => AccessResult::allowedIfHasPermissions($account, ['edit artifact', $admin], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, ['delete artifact', $admin], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create artifact', 'administer divengine'], 'OR');
  }

}
