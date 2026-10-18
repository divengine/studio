<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_project\ProjectVisibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access to timesheet entries.
 *
 * People see and change their own entries on projects they see. Project
 * managers (of the project or an ancestor) and people who manage
 * timesheets see and correct everyone's entries on those projects.
 */
class TimesheetAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  protected ProjectVisibility $visibility;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->visibility = $container->get('divengine_project.visibility');
    return $instance;
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\divengine_timesheet\Entity\Timesheet $entity */
    $project = $entity->get('project')->target_id;
    $result = AccessResult::allowedIf(
      $this->visibility->canSee($account, $project) && (
        ((int) $entity->getOwnerId() === (int) $account->id() && $account->hasPermission('log time'))
        || $this->supervises($account, $project)
      )
    );
    return $result->cachePerUser()->addCacheableDependency($entity)->addCacheTags(['project_list']);
  }

  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['log time', 'manage timesheets'], 'OR');
  }

  /**
   * Whether a user sees everyone's entries on a project.
   */
  protected function supervises(AccountInterface $account, int|string|null $project): bool {
    return $account->hasPermission('manage timesheets') || $account->hasPermission('administer projects')
      || in_array((string) $project, $this->visibility->managedIds($account), TRUE);
  }

}
