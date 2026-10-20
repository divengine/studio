<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\divengine_traceability\Entity\TraceLink;
use Drupal\divengine_traceability\Entity\TraceLinkInterface;

/**
 * Unified Process rules of trace links, for validation and storage alike.
 *
 * Records of a Development are traced only to records of the same
 * Development or to records outside any Development; the Development of a
 * record is resolved by DevelopmentReferences, so knowledge articles count
 * through kb_development and Projects, which belong to no Development, are
 * not forced into one. Only a change request impacts Unified Process
 * records. Includes and specializes never form a cycle; extends may point
 * back.
 */
class TraceRules {

  /**
   * Relations whose graph must stay acyclic.
   */
  const ACYCLIC = ['includes', 'generalizes'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DevelopmentReferences $references,
    protected Connection $database,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * The rules a link breaks.
   *
   * @return string[]
   *   Rule names: cross_development, impacts, cycle.
   */
  public function broken(TraceLinkInterface $link): array {
    $source = $link->getSourceEntity();
    $target = $link->getTargetEntity();
    if (!$source || !$target) {
      return [];
    }
    $broken = [];
    $source_development = $this->references->developmentOf($source);
    $target_development = $this->references->developmentOf($target);
    if ($source_development && $target_development && $source_development !== $target_development) {
      $broken[] = 'cross_development';
    }
    $type = (string) $link->get('link_type')->value;
    if ($type === 'impacts' && ($source_development || $target_development) && $source->getEntityTypeId() !== 'change_request') {
      $broken[] = 'impacts';
    }
    if (in_array($type, static::ACYCLIC, TRUE) && $this->reaches($target, $source, $type, $link->id())) {
      $broken[] = 'cycle';
    }
    return $broken;
  }

  /**
   * Refuses to store a link that breaks a rule, whatever the writer.
   *
   * Writers of the same acyclic relation are serialized until the first
   * one's transaction ends, so two links that would close a cycle together
   * cannot both pass.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function assertStorable(TraceLink $link): void {
    $type = (string) $link->get('link_type')->value;
    if (in_array($type, static::ACYCLIC, TRUE)) {
      $lock = 'divengine_process_trace_graph:' . $type;
      $deadline = time() + 30;
      while (!$this->lock->acquire($lock)) {
        if (time() >= $deadline) {
          throw new EntityStorageException('Another request is changing the same relation graph. Try again.');
        }
        $this->lock->wait($lock, 5);
      }
      if ($this->database->inTransaction()) {
        $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->lock->release($lock));
      }
      try {
        $broken = $this->broken($link);
      }
      finally {
        if (!$this->database->inTransaction()) {
          $this->lock->release($lock);
        }
      }
    }
    else {
      $broken = $this->broken($link);
    }
    if ($broken) {
      throw new EntityStorageException(sprintf('The trace link breaks Unified Process rules: %s.', implode(', ', $broken)));
    }
  }

  /**
   * Whether $from already reaches $to through links of the same type.
   */
  public function reaches(EntityInterface $from, EntityInterface $to, string $type, int|string|null $exclude): bool {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $goal = $to->getEntityTypeId() . ':' . $to->id();
    $frontier = [$from->getEntityTypeId() . ':' . $from->id()];
    $seen = [];
    while ($frontier) {
      $key = array_shift($frontier);
      if ($key === $goal) {
        return TRUE;
      }
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      [$entity_type, $id] = explode(':', $key, 2);
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('link_type', $type)
        ->condition('source_entity_type', $entity_type)
        ->condition('source_entity_id', $id);
      if ($exclude) {
        $query->condition('id', $exclude, '<>');
      }
      foreach ($storage->loadMultiple($query->execute()) as $next) {
        $frontier[] = $next->getTargetEntityType() . ':' . $next->getTargetEntityId();
      }
    }
    return FALSE;
  }

}
