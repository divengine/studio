<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_process\Entity\DevelopmentInterface;

/**
 * Explicit-traceability gaps of a Development.
 *
 * A gap is something the process expects to be traced and is not; nothing
 * is ever inferred to fill it.
 */
class TraceabilityGaps {

  /**
   * Relations that take a requirement downstream.
   */
  const DOWNSTREAM = ['refines', 'realizes', 'implements'];

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Approved or baselined requirements nothing refines downstream.
   *
   * A requirement is refined when a system use case covers it or a trace
   * refines, realizes or implements it.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function requirementsNotRefined(DevelopmentInterface $development): array {
    $requirements = array_filter(
      $this->artifacts($development, 'requirement'),
      fn (ContentEntityInterface $r) => in_array($r->get('moderation_state')->value, ['approved', 'published'], TRUE),
    );
    return array_filter($requirements, fn (ContentEntityInterface $r) => !$this->isRefined($r));
  }

  /**
   * Whether a requirement is covered by a use case or traced downstream.
   */
  public function isRefined(ContentEntityInterface $requirement): bool {
    $artifacts = $this->entityTypeManager->getStorage('artifact');
    $covered = $artifacts->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'use_case')
      ->condition('field_requirements', $requirement->id())
      ->count()
      ->execute();
    if ($covered) {
      return TRUE;
    }
    return (bool) $this->entityTypeManager->getStorage('trace_link')->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type', 'artifact')
      ->condition('target_entity_id', $requirement->id())
      ->condition('link_type', static::DOWNSTREAM, 'IN')
      ->count()
      ->execute();
  }

  /**
   * Accepted or scheduled change requests without an impacted record.
   *
   * @return \Drupal\divengine_process\Entity\ChangeRequest[]
   */
  public function changeRequestsWithoutImpact(DevelopmentInterface $development): array {
    $storage = $this->entityTypeManager->getStorage('change_request');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('development', $development->id())
      ->condition('state', ['accepted', 'scheduled'], 'IN')
      ->condition('status', 1)
      ->execute();
    return array_filter($storage->loadMultiple($ids), fn ($cr) => !$this->hasImpact($cr));
  }

  /**
   * Whether a change request has at least one "impacts" trace.
   */
  public function hasImpact(ContentEntityInterface $change_request): bool {
    return (bool) $this->entityTypeManager->getStorage('trace_link')->getQuery()
      ->accessCheck(FALSE)
      ->condition('source_entity_type', 'change_request')
      ->condition('source_entity_id', $change_request->id())
      ->condition('link_type', 'impacts')
      ->count()
      ->execute();
  }

  /**
   * Active artifacts of a type in a Development.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function artifacts(DevelopmentInterface $development, string $bundle): array {
    $storage = $this->entityTypeManager->getStorage('artifact');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('development', $development->id())
      ->condition('type', $bundle)
      ->execute();
    return $storage->loadMultiple($ids);
  }

}
