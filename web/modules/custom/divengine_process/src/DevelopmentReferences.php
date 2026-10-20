<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Which records belong to a Development, and through which field.
 *
 * Every entity type whose rows follow the visibility of a Development
 * (hook_divengine_process_scoped_types_alter()) belongs to it through its
 * "development" field. Modules whose records point to a Development through
 * another field declare it with
 * hook_divengine_process_development_references_alter(), e.g. knowledge
 * articles through kb_development. A type or field that is not installed is
 * ignored.
 */
class DevelopmentReferences {

  /**
   * The structure of a Development: deleted with it, never blocking it.
   */
  const STRUCTURE = ['development_phase', 'iteration', 'milestone', 'role_assignment'];

  protected ?array $references = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Field names keyed by entity type ID, structure included.
   *
   * @return array<string, string>
   */
  public function references(): array {
    if ($this->references === NULL) {
      $references = array_fill_keys(divengine_process_scoped_types(), 'development');
      $this->moduleHandler->alter('divengine_process_development_references', $references);
      $this->references = array_filter($references, fn (string $field, string $entity_type_id) => $this->hasField($entity_type_id, $field), ARRAY_FILTER_USE_BOTH);
    }
    return $this->references;
  }

  /**
   * The Development a record belongs to, if any.
   */
  public function developmentOf(EntityInterface $entity): ?string {
    if ($entity->getEntityTypeId() === 'development') {
      return (string) $entity->id();
    }
    $field = $this->references()[$entity->getEntityTypeId()] ?? NULL;
    if ($field && $entity instanceof FieldableEntityInterface && $entity->hasField($field) && !$entity->get($field)->isEmpty()) {
      return (string) $entity->get($field)->target_id;
    }
    return NULL;
  }

  /**
   * How many records of each type still belong to a Development, its
   * structure excepted.
   *
   * @return array<string, int>
   *   Counts keyed by entity type ID, only types with records.
   */
  public function dependents(int|string $development_id): array {
    $counts = [];
    foreach (array_diff_key($this->references(), array_flip(static::STRUCTURE)) as $entity_type_id => $field) {
      $count = (int) $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $development_id)
        ->count()
        ->execute();
      if ($count) {
        $counts[$entity_type_id] = $count;
      }
    }
    return $counts;
  }

  /**
   * List cache tags of every dependent type.
   *
   * @return string[]
   */
  public function cacheTags(): array {
    $tags = [];
    foreach (array_keys(array_diff_key($this->references(), array_flip(static::STRUCTURE))) as $entity_type_id) {
      $tags = array_merge($tags, $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags());
    }
    return $tags;
  }

  protected function hasField(string $entity_type_id, string $field): bool {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return FALSE;
    }
    return isset($this->entityFieldManager->getFieldStorageDefinitions($entity_type_id)[$field]);
  }

}
