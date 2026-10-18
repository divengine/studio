<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Project configuration: everyone working on projects reads the labels,
 * project administrators change it.
 */
class ProjectConfigAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if (in_array($operation, ['view', 'view label'], TRUE)) {
      return AccessResult::allowedIfHasPermissions($account, ['use projects', 'manage projects', 'administer projects'], 'OR');
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
