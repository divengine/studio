<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Activity entries are seen with their record and never edited.
 */
class QaLogAccessControlHandler extends EntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view') {
      return AccessResult::forbidden();
    }
    $target = \Drupal::entityTypeManager()->getStorage($entity->get('target_type')->value)->load($entity->get('target_id')->value);
    return $target ? $target->access('view', $account, TRUE) : AccessResult::forbidden();
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::forbidden();
  }

}
