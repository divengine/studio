<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\Entity\DevelopmentInterface;

/**
 * Who sees a Development, and therefore everything that belongs to it.
 *
 * A Development is visible to its members, its creator, its Responsible and
 * Process Managers ("administer unified process"), provided they use the
 * Unified Process. Records of a Development are visible exactly when the
 * Development is. Process roles never grant visibility.
 */
class DevelopmentVisibility {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether the account sees the Development.
   */
  public function access(DevelopmentInterface $development, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer unified process')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$account->hasPermission('use unified process')) {
      return AccessResult::neutral()->cachePerPermissions();
    }
    return AccessResult::allowedIf($development->isVisibleTo((int) $account->id()))
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($development);
  }

  /**
   * Restricts a query on a Development-scoped entity type to visible rows.
   *
   * Developments are restricted to the visible ones; records that belong to
   * a Development follow it; records without a Development (artifacts
   * outside the Unified Process) are left to their own access rules.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   An entity query or a Views query tagged ENTITY_TYPE_access.
   * @param string $entity_type_id
   *   The entity type of the query.
   */
  public function alterQuery(SelectInterface $query, string $entity_type_id): void {
    $account = $query->getMetaData('account') ?: \Drupal::currentUser();
    if ($account->hasPermission('administer unified process')) {
      return;
    }
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $tables = array_filter([$entity_type->getBaseTable(), $entity_type->getDataTable()]);
    $alias = NULL;
    foreach ($query->getTables() as $table_alias => $info) {
      if (in_array($info['table'] ?? NULL, $tables, TRUE)) {
        $alias = $table_alias;
        break;
      }
    }
    if ($alias === NULL) {
      return;
    }
    $id = $entity_type->getKey('id');
    if ($entity_type_id === 'development') {
      if (!$account->hasPermission('use unified process')) {
        $query->alwaysFalse();
        return;
      }
      $query->condition("$alias.$id", $this->visibleDevelopments((int) $account->id()), 'IN');
      return;
    }

    // Rows whose Development is visible, plus rows without a Development.
    $field_table = $entity_type->getDataTable() ?: $entity_type->getBaseTable();
    $visible = $this->database->select($field_table, 'scoped')->fields('scoped', [$id]);
    $condition = $visible->orConditionGroup()->isNull('scoped.development');
    if ($account->hasPermission('use unified process')) {
      $condition->condition('scoped.development', $this->visibleDevelopments((int) $account->id()), 'IN');
    }
    $visible->condition($condition);
    $query->condition("$alias.$id", $visible, 'IN');
  }

  /**
   * A subquery of the IDs of the Developments a user sees.
   *
   * The caller has checked that the user uses the Unified Process and is not
   * a Process Manager.
   */
  public function visibleDevelopments(int $uid): SelectInterface {
    $members = $this->database->select('development__members', 'm')
      ->fields('m', ['entity_id'])
      ->condition('m.members_target_id', $uid);
    $query = $this->database->select('development', 'd')->fields('d', ['id']);
    $query->condition($query->orConditionGroup()
      ->condition('d.uid', $uid)
      ->condition('d.responsible', $uid)
      ->condition('d.id', $members, 'IN'));
    return $query;
  }

}
