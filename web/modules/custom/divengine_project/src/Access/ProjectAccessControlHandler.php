<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_project\ProjectVisibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access to projects and tasks.
 *
 * Everything follows project visibility. Within a visible project:
 * - projects are edited by their managers (or those of an ancestor) and by
 *   people who manage projects; deleted by their owner or those people;
 * - tasks are worked on by everyone in the project; deleted by their
 *   reporter, the project managers or people who manage projects.
 */
class ProjectAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  protected ProjectVisibility $visibility;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->visibility = $container->get('divengine_project.visibility');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $project_id = $entity->getEntityTypeId() === 'project' ? $entity->id() : $entity->get('project')->target_id;
    if (!$this->visibility->canSee($account, $project_id)) {
      return AccessResult::forbidden()->cachePerUser()->addCacheTags(['project_list']);
    }
    $manage = $account->hasPermission('manage projects') || $account->hasPermission('administer projects');
    $manages = $manage || $this->visibility->manages($account, $project_id);
    $allowed = match ($operation) {
      'view', 'view label', 'view revision', 'view all revisions' => TRUE,
      'update', 'revert revision' => $entity->getEntityTypeId() === 'project' ? $manages : TRUE,
      'delete', 'delete revision' => $entity->getEntityTypeId() === 'project'
        ? ($manage || (int) $entity->getOwnerId() === (int) $account->id())
        : ($manages || (int) $entity->getOwnerId() === (int) $account->id()),
      default => FALSE,
    };
    return AccessResult::allowedIf($allowed)->cachePerUser()->addCacheableDependency($entity)->addCacheTags(['project_list']);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $this->entityTypeId === 'project'
      ? AccessResult::allowedIfHasPermissions($account, ['manage projects', 'administer projects'], 'OR')
      : AccessResult::allowedIfHasPermissions($account, ['use projects', 'manage projects', 'administer projects'], 'OR');
  }

}
