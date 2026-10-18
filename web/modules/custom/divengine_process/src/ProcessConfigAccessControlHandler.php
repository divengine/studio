<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process configuration: readable by process users, edited by Process Managers.
 *
 * Configuration that other configuration still references cannot be deleted
 * (Odoo's "restrict"): deleting it would silently drop or rewrite the
 * dependents.
 */
class ProcessConfigAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  protected ConfigManagerInterface $configManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->configManager = $container->get('config.manager');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    if ($operation === 'view' || $operation === 'view label') {
      return AccessResult::allowedIfHasPermissions($account, ['use unified process', 'administer unified process'], 'OR');
    }
    $admin = AccessResult::allowedIfHasPermission($account, 'administer unified process');
    if ($operation === 'delete' && $admin->isAllowed()) {
      $dependents = $this->configManager->findConfigEntityDependencies('config', [$entity->getConfigDependencyName()]);
      if ($dependents) {
        return AccessResult::forbidden('Other process configuration still uses it.')
          ->addCacheableDependency($entity)
          ->addCacheTags(['config:' . $entity->getEntityType()->getConfigPrefix() . '_list']);
      }
    }
    return $admin;
  }

}
