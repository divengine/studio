<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Keeps the parent chains of projects and tasks free of cycles.
 *
 * The ProjectHierarchy constraint reports a cycle to forms and the API; this
 * holds for every writer. Writers that change parents of the same entity
 * type are serialized until the first one's transaction ends, and the chain
 * is read from storage, so two moves that would close a cycle together
 * cannot both pass.
 */
class ProjectHierarchy {

  public function __construct(
    protected Connection $database,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function assertAcyclic(ContentEntityInterface $entity): void {
    $parent = $entity->get('parent')->target_id;
    $original = $entity->isNew() ? NULL : $entity->getOriginal();
    // A new record has no children yet, so it cannot close a cycle.
    if (!$parent || !$original || (string) $original->get('parent')->target_id === (string) $parent) {
      return;
    }
    $type = $entity->getEntityType();
    $lock = 'divengine_project_hierarchy:' . $type->id();
    $deadline = time() + 30;
    while (!$this->lock->acquire($lock)) {
      if (time() >= $deadline) {
        throw new EntityStorageException('Another request is changing the same hierarchy. Try again.');
      }
      $this->lock->wait($lock, 5);
    }
    if ($this->database->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->lock->release($lock));
    }
    try {
      $cycle = $this->reaches((string) $parent, (string) $entity->id(), $type->getBaseTable());
    }
    finally {
      if (!$this->database->inTransaction()) {
        $this->lock->release($lock);
      }
    }
    if ($cycle) {
      throw new EntityStorageException(sprintf('%s %s cannot be placed under one of its own descendants.', $type->getLabel(), $entity->label()));
    }
  }

  /**
   * Whether the stored parent chain from $from passes through $id.
   */
  protected function reaches(string $from, string $id, string $table): bool {
    $seen = [];
    for ($node = $from; $node !== '' && !isset($seen[$node]); $node = (string) $this->database->select($table, 't')->fields('t', ['parent'])->condition('t.id', $node)->execute()->fetchField()) {
      if ($node === $id) {
        return TRUE;
      }
      $seen[$node] = TRUE;
    }
    return FALSE;
  }

}
