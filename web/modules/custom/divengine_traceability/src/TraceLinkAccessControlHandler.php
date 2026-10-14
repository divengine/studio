<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

class TraceLinkAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin = 'administer divengine';
    $trace_admin = 'administer trace links';

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermissions($account, ['view trace links', $admin, $trace_admin], 'OR'),
      'update' => AccessResult::allowedIfHasPermissions($account, ['edit trace links', $admin, $trace_admin], 'OR'),
      'delete' => AccessResult::allowedIfHasPermissions($account, ['delete trace links', $admin, $trace_admin], 'OR'),
      default => AccessResult::neutral(),
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create trace links', 'administer divengine', 'administer trace links'], 'OR');
  }

}
