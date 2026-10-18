<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * QA configuration: readable by QA users, edited by QA Managers.
 *
 * Configuration that plans or cases still use cannot be deleted.
 */
class QaConfigAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Where each configuration type is referenced: entity type => field.
   */
  const USAGE = [
    'qa_environment' => ['qa_test_plan' => 'environment'],
    'qa_stage' => ['qa_test_plan' => 'stage'],
    'qa_priority' => ['qa_test_case' => 'priority'],
    'qa_tag' => ['qa_test_plan' => 'tags', 'qa_test_suite' => 'tags', 'qa_test_case' => 'tags', 'qa_test_run' => 'tags'],
  ];

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view' || $operation === 'view label') {
      return AccessResult::allowedIfHasPermissions($account, ['use qa', 'administer qa'], 'OR');
    }
    $manager = AccessResult::allowedIfHasPermission($account, 'administer qa');
    if ($operation === 'delete' && $manager->isAllowed() && $this->inUse($entity)) {
      return AccessResult::forbidden('Test plans or cases still use it.')->addCacheTags(['qa_test_plan_list', 'qa_test_case_list']);
    }
    return $manager;
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer qa');
  }

  protected function inUse(EntityInterface $entity): bool {
    foreach (static::USAGE[$entity->getEntityTypeId()] ?? [] as $entity_type_id => $field) {
      $count = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $entity->id())
        ->count()
        ->execute();
      if ($count) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
