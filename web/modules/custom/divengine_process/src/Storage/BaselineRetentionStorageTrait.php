<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Storage;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/** Retention is a persistence invariant, independent of caller permissions. */
trait BaselineRetentionStorageTrait {

  public function deleteRevision($revision_id) {
    // Core has no pre-delete revision hook; its revision_delete hook runs only
    // after field values have already been removed.
    if (\Drupal::service('divengine_process.baselines')->protectsRevision($this->entityTypeId, $revision_id)) {
      throw new EntityStorageException('A baseline holds this revision; it cannot be deleted.');
    }
    parent::deleteRevision($revision_id);
  }

  public function delete(array $entities) {
    // Check the entire batch before invoking any delete hooks or cascades.
    foreach ($entities as $entity) {
      if (\Drupal::service('divengine_process.baselines')->protectsRecord($this->entityTypeId, $entity->id())) {
        throw new EntityStorageException('A baseline holds a revision of this record; it cannot be deleted.');
      }
    }
    parent::delete($entities);
  }

  protected function doSaveFieldItems(ContentEntityInterface $entity, array $names = []) {
    if (!$entity->isNew() && !$entity->isNewRevision()
      && \Drupal::service('divengine_process.baselines')->protectsRevision($this->entityTypeId, $entity->getRevisionId())) {
      throw new EntityStorageException('A baseline holds this revision; changes require a new revision.');
    }
    parent::doSaveFieldItems($entity, $names);
  }

  public function purgeFieldData(FieldDefinitionInterface $field_definition, $batch_size) {
    $this->assertFieldRetention($field_definition->getFieldStorageDefinition(), $field_definition->getTargetBundle());
    return parent::purgeFieldData($field_definition, $batch_size);
  }

  public function finalizePurge(FieldStorageDefinitionInterface $storage_definition) {
    $this->assertFieldRetention($storage_definition);
    parent::finalizePurge($storage_definition);
  }

  /** Refuse field purges before field delete callbacks can remove evidence. */
  protected function assertFieldRetention(FieldStorageDefinitionInterface $definition, ?string $bundle = NULL): void {
    $definitions = $this->fieldStorageDefinitions;
    $definitions[$definition->getName()] = $definition;
    $mapping = $this->getTableMapping($definitions);
    if (!$mapping->requiresDedicatedTableStorage($definition)) {
      return;
    }
    $table = $mapping->getDedicatedRevisionTableName($definition, $definition->isDeleted());
    if (!$this->database->schema()->tableExists($table)) {
      return;
    }
    $query = $this->database->select($table, 'f');
    $query->innerJoin('divengine_process_baseline_item', 'i', 'i.entity_id = f.entity_id AND i.revision_id = f.revision_id');
    $query->condition('i.entity_type', $this->entityTypeId);
    if ($bundle !== NULL) {
      $query->condition('f.bundle', $bundle);
    }
    if ($query->countQuery()->execute()->fetchField()) {
      throw new EntityStorageException('A baseline holds values of this field; they cannot be purged.');
    }
  }

}
