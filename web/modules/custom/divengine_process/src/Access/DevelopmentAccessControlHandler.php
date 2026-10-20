<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\DevelopmentReferences;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access to Developments.
 *
 * Whoever sees a Development may work on it; only Process Managers delete
 * Developments or their revisions, and only once no engineering record
 * belongs to it any more (see DevelopmentReferences; storage enforces the
 * same rule in Development::preDelete()).
 */
class DevelopmentAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  protected DevelopmentVisibility $visibility;

  protected DevelopmentReferences $references;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->visibility = $container->get('divengine_process.visibility');
    $instance->references = $container->get('divengine_process.development_references');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\divengine_process\Entity\DevelopmentInterface $entity */
    $manager = AccessResult::allowedIfHasPermission($account, 'administer unified process');
    return match ($operation) {
      'view', 'view label', 'update', 'view revision', 'view all revisions' => $this->visibility->access($entity, $account),
      'delete' => ($this->references->dependents($entity->id())
        ? AccessResult::forbidden('The Development still has records.')
        : $manager)->addCacheTags($this->references->cacheTags()),
      default => $manager,
    };
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
  }

}
