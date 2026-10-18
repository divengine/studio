<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Who sees which projects.
 *
 * A user sees the projects they own, manage or are a member of, and every
 * subproject below them. Project administrators see all projects. Tasks
 * follow their project.
 */
class ProjectVisibility {

  /**
   * Visible project IDs per user, for the request.
   *
   * @var array<int, string[]>
   */
  protected array $cache = [];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function seesAll(AccountInterface $account): bool {
    return $account->hasPermission('administer projects');
  }

  /**
   * IDs of the projects a user sees (ignored when they see all).
   *
   * @return string[]
   */
  public function visibleIds(AccountInterface $account): array {
    $uid = (int) $account->id();
    if (isset($this->cache[$uid])) {
      return $this->cache[$uid];
    }
    if (!$uid || !($account->hasPermission('use projects') || $account->hasPermission('manage projects'))) {
      return $this->cache[$uid] = [];
    }
    $members = $this->database->select('project__members', 'm')->fields('m', ['entity_id'])->condition('m.members_target_id', $uid);
    $query = $this->database->select('project', 'p')->fields('p', ['id']);
    $query->condition($query->orConditionGroup()
      ->condition('p.uid', $uid)
      ->condition('p.manager', $uid)
      ->condition('p.id', $members, 'IN'));
    return $this->cache[$uid] = $this->withDescendants(array_map('strval', $query->execute()->fetchCol()));
  }

  /**
   * IDs of the projects a user manages: owned or managed, and every
   * subproject below them.
   *
   * @return string[]
   */
  public function managedIds(AccountInterface $account): array {
    $uid = (int) $account->id();
    if (!$uid) {
      return [];
    }
    $query = $this->database->select('project', 'p')->fields('p', ['id']);
    $query->condition($query->orConditionGroup()->condition('p.uid', $uid)->condition('p.manager', $uid));
    return $this->withDescendants(array_map('strval', $query->execute()->fetchCol()));
  }

  /**
   * Adds the subprojects of some projects, level by level.
   *
   * @param string[] $ids
   *
   * @return string[]
   */
  public function withDescendants(array $ids): array {
    $frontier = $ids;
    while ($frontier) {
      $children = array_map('strval', $this->database->select('project', 'p')->fields('p', ['id'])->condition('p.parent', $frontier, 'IN')->execute()->fetchCol());
      $frontier = array_values(array_diff($children, $ids));
      $ids = array_merge($ids, $frontier);
    }
    return $ids;
  }

  public function canSee(AccountInterface $account, int|string|null $project_id): bool {
    return $project_id !== NULL && ($this->seesAll($account) || in_array((string) $project_id, $this->visibleIds($account), TRUE));
  }

  /**
   * Whether a user manages a project: its owner or manager, or those of an
   * ancestor.
   */
  public function manages(AccountInterface $account, int|string|null $project_id): bool {
    $storage = $this->entityTypeManager->getStorage('project');
    $seen = [];
    for ($project = $project_id ? $storage->load($project_id) : NULL; $project && !isset($seen[$project->id()]); $project = $project->get('parent')->entity) {
      $seen[$project->id()] = TRUE;
      if ((int) $project->getOwnerId() === (int) $account->id() || (int) $project->get('manager')->target_id === (int) $account->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Restricts a project or task query to what the user sees.
   */
  public function alterQuery(SelectInterface $query, string $entity_type_id): void {
    $account = $query->getMetaData('account') ?: \Drupal::currentUser();
    if ($this->seesAll($account)) {
      return;
    }
    $table = $this->entityTypeManager->getDefinition($entity_type_id)->getBaseTable();
    $alias = NULL;
    foreach ($query->getTables() as $table_alias => $info) {
      if (($info['table'] ?? NULL) === $table) {
        $alias = $table_alias;
        break;
      }
    }
    if ($alias === NULL) {
      return;
    }
    $ids = $this->visibleIds($account);
    if (!$ids) {
      $query->alwaysFalse();
      return;
    }
    $query->condition($alias . '.' . ($entity_type_id === 'project' ? 'id' : 'project'), $ids, 'IN');
  }

}
