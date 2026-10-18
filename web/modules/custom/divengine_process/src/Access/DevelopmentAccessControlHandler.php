<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access to Developments.
 *
 * Whoever sees a Development may work on it; only Process Managers delete
 * Developments or their revisions, and only once no engineering record
 * belongs to it any more.
 */
class DevelopmentAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Records that keep a Development from being deleted.
   *
   * Its phases, iterations, milestones and role assignments are deleted
   * with it; engineering records and decisions are not thrown away with it.
   */
  const RECORDS = ['artifact', 'process_activity', 'change_request', 'baseline'];

  protected DevelopmentVisibility $visibility;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->visibility = $container->get('divengine_process.visibility');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Whether engineering records still belong to the Development.
   */
  protected function hasRecords(EntityInterface $development): bool {
    foreach (static::RECORDS as $entity_type_id) {
      $count = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE)
        ->condition('development', $development->id())
        ->count()
        ->execute();
      if ($count) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\divengine_process\Entity\DevelopmentInterface $entity */
    $manager = AccessResult::allowedIfHasPermission($account, 'administer unified process');
    return match ($operation) {
      'view', 'view label', 'update', 'view revision', 'view all revisions' => $this->visibility->access($entity, $account),
      'delete' => $this->hasRecords($entity)
        ? AccessResult::forbidden('The Development still has records.')->addCacheTags(['artifact_list', 'process_activity_list', 'change_request_list', 'baseline_list'])
        : $manager,
      default => $manager,
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
  }

}
