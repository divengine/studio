<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_traceability\Entity\TraceLinkType;

class TraceLinkValidator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function validateSource(string $entity_type_id, int $entity_id): array {
    $errors = [];
    if (empty($entity_type_id) || $entity_id <= 0) {
      $errors[] = 'Source entity type and ID are required.';
      return $errors;
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      $errors[] = sprintf('Source entity type "%s" does not exist.', $entity_type_id);
      return $errors;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage->load($entity_id)) {
      $errors[] = sprintf('Source entity of type "%s" with ID %d does not exist.', $entity_type_id, $entity_id);
    }
    return $errors;
  }

  public function validateTarget(string $entity_type_id, int $entity_id): array {
    $errors = [];
    if (empty($entity_type_id) || $entity_id <= 0) {
      $errors[] = 'Target entity type and ID are required.';
      return $errors;
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      $errors[] = sprintf('Target entity type "%s" does not exist.', $entity_type_id);
      return $errors;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage->load($entity_id)) {
      $errors[] = sprintf('Target entity of type "%s" with ID %d does not exist.', $entity_type_id, $entity_id);
    }
    return $errors;
  }

  public function validateDistinct(string $source_type, int $source_id, string $target_type, int $target_id): array {
    $errors = [];
    if (!empty($source_type) && !empty($target_type) && $source_id > 0 && $target_id > 0) {
      if ($source_type === $target_type && $source_id === $target_id) {
        $errors[] = 'Source and target cannot be the same entity.';
      }
    }
    return $errors;
  }

  /**
   * Checks the link type and whether it accepts both ends.
   */
  public function validateType(string $link_type, string $source_type, int $source_id, string $target_type, int $target_id): array {
    if ($link_type === '') {
      return ['A link type is required.'];
    }
    $type = $this->entityTypeManager->getStorage('trace_link_type')->load($link_type);
    if (!$type instanceof TraceLinkType) {
      return [sprintf('Link type "%s" does not exist.', $link_type)];
    }
    $source = $this->loadEnd($source_type, $source_id);
    $target = $this->loadEnd($target_type, $target_id);
    if (!$source || !$target) {
      // Missing ends are reported by validateSource() and validateTarget().
      return [];
    }
    $errors = [];
    if (!$type->allowsSource($source)) {
      $errors[] = sprintf('"%s" links cannot start from %s.', $type->label(), $this->describe($source));
    }
    if (!$type->allowsTarget($target)) {
      $errors[] = sprintf('"%s" links cannot point to %s.', $type->label(), $this->describe($target));
    }
    if ($type->requiresSameKind() && $this->describe($source) !== $this->describe($target)) {
      $errors[] = sprintf('"%s" links must join two entities of the same kind.', $type->label());
    }
    return $errors;
  }

  /**
   * Checks that no other link records the same source, type and target.
   */
  public function validateUnique(string $link_type, string $source_type, int $source_id, string $target_type, int $target_id, ?int $exclude_id = NULL): array {
    if ($link_type === '' || $source_id <= 0 || $target_id <= 0) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('trace_link')->getQuery()
      ->accessCheck(FALSE)
      ->condition('link_type', $link_type)
      ->condition('source_entity_type', $source_type)
      ->condition('source_entity_id', $source_id)
      ->condition('target_entity_type', $target_type)
      ->condition('target_entity_id', $target_id);
    if ($exclude_id) {
      $query->condition('id', $exclude_id, '<>');
    }
    return $query->count()->execute() > 0 ? ['This trace link already exists.'] : [];
  }

  public function validateAll(string $source_type, int $source_id, string $target_type, int $target_id, string $link_type = '', ?int $exclude_id = NULL): array {
    $errors = array_merge(
      $this->validateSource($source_type, $source_id),
      $this->validateTarget($target_type, $target_id),
      $this->validateDistinct($source_type, $source_id, $target_type, $target_id),
    );
    if ($link_type !== '') {
      $errors = array_merge(
        $errors,
        $this->validateType($link_type, $source_type, $source_id, $target_type, $target_id),
        $this->validateUnique($link_type, $source_type, $source_id, $target_type, $target_id, $exclude_id),
      );
    }
    return $errors;
  }

  protected function loadEnd(string $entity_type_id, int $entity_id): ?EntityInterface {
    if ($entity_type_id === '' || $entity_id <= 0 || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
  }

  protected function describe(EntityInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->bundle();
  }

}
