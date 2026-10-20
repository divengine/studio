<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;

/** Applies the actual endpoint access policies, including optional modules. */
class TraceVisibility {

  protected array $checking = [];

  protected array $queryCacheability = [];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function endsAccess(TraceLinkInterface $link, AccountInterface $account): AccessResultInterface {
    $key = $link->uuid() . ':' . $link->getRevisionId() . ':' . $account->id();
    $result = AccessResult::allowed()->cachePerUser()->cachePerPermissions()->addCacheableDependency($link);
    if (isset($this->checking[$key])) {
      return AccessResult::forbidden('Circular trace endpoints.')->setCacheMaxAge(0);
    }
    $this->checking[$key] = TRUE;
    try {
      foreach ([$link->getSourceEntity(), $link->getTargetEntity()] as $end) {
        if (!$end) {
          return AccessResult::forbidden('A trace endpoint is missing.')
            ->addCacheableDependency($result)->setCacheMaxAge(0);
        }
        $access = $end->access('view', $account, TRUE);
        $allowed = $access->isAllowed() ? AccessResult::allowed() : AccessResult::forbidden('A trace endpoint is not viewable.');
        $result = $result->andIf($allowed->addCacheableDependency($access)->addCacheableDependency($end));
      }
    }
    finally {
      unset($this->checking[$key]);
    }
    return $result;
  }

  /**
   * Filters before SQL range/count, not after pagination or serialization.
   *
   * Polymorphic endpoints have no universal SQL ACL. Read distinct referenced
   * IDs in batches and delegate to each entity's handler. Do not cache allow
   * lists: a later query must re-evaluate the current account and entity state.
   */
  public function alterQuery(SelectInterface $query): void {
    $account = $query->getMetaData('account') ?: \Drupal::currentUser();
    if (!$account->hasPermission('view trace links') && !$account->hasPermission('administer trace links') && !$account->hasPermission('administer divengine')) {
      $query->alwaysFalse();
      return;
    }
    $definition = $this->entityTypeManager->getDefinition('trace_link');
    $tables = array_filter([$definition->getBaseTable(), $definition->getDataTable(), $definition->getRevisionTable(), $definition->getRevisionDataTable()]);
    foreach ($query->getTables() as $alias => $info) {
      if (!is_string($info['table']) || !in_array($info['table'], $tables, TRUE)) {
        continue;
      }
      foreach (['source', 'target'] as $end) {
        $types = $this->database->select($info['table'], 'ends')->fields('ends', [$end . '_entity_type'])->distinct()->execute()->fetchCol();
        $allowed = $query->orConditionGroup();
        foreach ($types as $type) {
          if (!$type || !$this->entityTypeManager->hasDefinition($type)) {
            continue;
          }
          $ids = $this->database->select($info['table'], 'ends')->fields('ends', [$end . '_entity_id'])
            ->condition($end . '_entity_type', $type)->distinct()->execute()->fetchCol();
          $metadata = new CacheableMetadata();
          $metadata->setCacheTags($this->entityTypeManager->getDefinition($type)->getListCacheTags());
          $this->remember($account, $metadata);
          foreach (array_chunk($ids, 100) as $batch) {
            $visible = [];
            foreach ($this->entityTypeManager->getStorage($type)->loadMultiple($batch) as $id => $entity) {
              $access = $entity->access('view', $account, TRUE);
              $this->remember($account, CacheableMetadata::createFromObject($access)->addCacheableDependency($entity));
              if ($access->isAllowed()) {
                $visible[] = $id;
              }
            }
            if ($visible) {
              $allowed->condition($query->andConditionGroup()
                ->condition("$alias.{$end}_entity_type", $type)
                ->condition("$alias.{$end}_entity_id", $visible, 'IN'));
            }
          }
        }
        if ($allowed->count()) {
          $query->condition($allowed);
        }
        else {
          $query->alwaysFalse();
        }
      }
    }
  }

  protected function remember(AccountInterface $account, CacheableMetadata $metadata): void {
    $uid = $account->id();
    $this->queryCacheability[$uid] = ($this->queryCacheability[$uid] ?? new CacheableMetadata())->merge($metadata);
  }

  public function queryCacheability(AccountInterface $account): CacheableMetadata {
    return ($this->queryCacheability[$account->id()] ?? new CacheableMetadata())
      ->merge((new CacheableMetadata())->setCacheContexts(['user', 'user.permissions'])->setCacheTags(['trace_link_list']));
  }

}
