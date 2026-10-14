<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Entity\EntityTypeManagerInterface;

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

  public function validateAll(string $source_type, int $source_id, string $target_type, int $target_id): array {
    return array_merge(
      $this->validateSource($source_type, $source_id),
      $this->validateTarget($target_type, $target_id),
      $this->validateDistinct($source_type, $source_id, $target_type, $target_id),
    );
  }

}
