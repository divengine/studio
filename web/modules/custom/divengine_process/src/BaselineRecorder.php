<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_process\Entity\Baseline;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Entity\Milestone;

/**
 * Takes baselines and answers which revisions they protect.
 *
 * A baseline holds, for every record of the Development that is baselined
 * at that moment, the exact revision in force: artifacts whose effective
 * revision is Baselined, artifacts without a lifecycle (actors, workers)
 * that are active, and the trace links between records of the baseline.
 * Traceability points to records; baselines point to revisions.
 */
class BaselineRecorder {

  const TABLE = 'divengine_process_baseline_item';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Takes a baseline of a Development.
   */
  public function take(DevelopmentInterface $development, string $name, ?Milestone $milestone = NULL, string $notes = ''): Baseline {
    $items = [];
    $artifacts = $this->entityTypeManager->getStorage('artifact');
    $ids = $artifacts->getQuery()->accessCheck(FALSE)->condition('development', $development->id())->execute();
    foreach ($artifacts->loadMultiple($ids) as $artifact) {
      if ($this->isBaselined($artifact)) {
        $items['artifact:' . $artifact->id()] = ['artifact', (int) $artifact->id(), (int) $artifact->getRevisionId()];
      }
    }

    $links = $this->entityTypeManager->getStorage('trace_link');
    if ($items) {
      $artifact_ids = array_map(fn ($item) => $item[1], $items);
      $link_ids = $links->getQuery()->accessCheck(FALSE)
        ->condition('source_entity_type', 'artifact')
        ->condition('source_entity_id', $artifact_ids, 'IN')
        ->condition('target_entity_type', 'artifact')
        ->condition('target_entity_id', $artifact_ids, 'IN')
        ->execute();
      foreach ($links->loadMultiple($link_ids) as $link) {
        $items['trace_link:' . $link->id()] = ['trace_link', (int) $link->id(), (int) $link->getRevisionId()];
      }
    }

    $baseline = $this->entityTypeManager->getStorage('baseline')->create([
      'development' => $development->id(),
      'name' => $name,
      'milestone' => $milestone?->id(),
      'notes' => $notes,
    ]);
    $baseline->save();
    $insert = $this->database->insert(static::TABLE)->fields(['baseline_id', 'entity_type', 'entity_id', 'revision_id']);
    foreach ($items as [$entity_type, $entity_id, $revision_id]) {
      $insert->values([$baseline->id(), $entity_type, $entity_id, $revision_id]);
    }
    if ($items) {
      $insert->execute();
    }
    return $baseline;
  }

  /**
   * Whether a record's effective revision belongs in a baseline.
   */
  public function isBaselined(ContentEntityInterface $artifact): bool {
    $state = $artifact->hasField('moderation_state') ? $artifact->get('moderation_state')->value : NULL;
    if ($state !== NULL && $state !== '') {
      return $state === 'published';
    }
    return (bool) $artifact->get('status')->value;
  }

  /**
   * The items of a baseline.
   *
   * @return array<int, array{entity_type: string, entity_id: int, revision_id: int}>
   */
  public function items(Baseline $baseline): array {
    return $this->database->select(static::TABLE, 'i')
      ->fields('i', ['entity_type', 'entity_id', 'revision_id'])
      ->condition('baseline_id', $baseline->id())
      ->orderBy('entity_type')
      ->orderBy('entity_id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Whether a revision belongs to some baseline.
   */
  public function protectsRevision(string $entity_type, int|string $revision_id): bool {
    return (bool) $this->database->select(static::TABLE, 'i')
      ->condition('entity_type', $entity_type)
      ->condition('revision_id', $revision_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Whether any revision of a record belongs to some baseline.
   */
  public function protectsRecord(string $entity_type, int|string $entity_id): bool {
    return (bool) $this->database->select(static::TABLE, 'i')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
