<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Which records mention which, through [[CODE]] in their texts.
 *
 * Kept in the table divengine_reference and refreshed whenever a record is
 * saved, so every record can show where it is mentioned ("backlinks").
 * Mentions are not traces: they point at what a text talks about, nothing
 * more.
 */
class ReferenceIndex {

  const TEXT_TYPES = ['text', 'text_long', 'text_with_summary', 'string_long'];

  protected ?bool $ready = NULL;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CodeResolver $resolver,
  ) {}

  /**
   * Re-reads the mentions of a record.
   */
  public function index(ContentEntityInterface $entity): void {
    $text_fields = array_keys(array_filter($entity->getFieldDefinitions(), fn ($definition) => in_array($definition->getType(), static::TEXT_TYPES, TRUE)));
    if (!$text_fields || !$this->ready()) {
      return;
    }
    $codes = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (in_array($name, $text_fields, TRUE)) {
        foreach ($entity->get($name) as $item) {
          $codes = array_merge($codes, $this->resolver->codesIn((string) $item->value . ' ' . (string) ($item->summary ?? '')));
        }
      }
    }
    $targets = [];
    foreach (array_unique($codes) as $code) {
      $target = $this->resolver->resolve($code);
      if ($target && !($target->getEntityTypeId() === $entity->getEntityTypeId() && (string) $target->id() === (string) $entity->id())) {
        $targets[$target->getEntityTypeId() . ':' . $target->id()] = $target;
      }
    }
    $transaction = $this->database->startTransaction();
    $this->database->delete('divengine_reference')
      ->condition('source_type', $entity->getEntityTypeId())
      ->condition('source_id', $entity->id())
      ->execute();
    foreach ($targets as $target) {
      $this->database->insert('divengine_reference')->fields([
        'source_type' => $entity->getEntityTypeId(),
        'source_id' => $entity->id(),
        'target_type' => $target->getEntityTypeId(),
        'target_id' => $target->id(),
      ])->execute();
    }
    unset($transaction);
  }

  /**
   * Forgets the mentions from and to a deleted record.
   */
  public function forget(EntityInterface $entity): void {
    if (!$this->ready()) {
      return;
    }
    foreach (['source', 'target'] as $end) {
      $this->database->delete('divengine_reference')
        ->condition($end . '_type', $entity->getEntityTypeId())
        ->condition($end . '_id', $entity->id())
        ->execute();
    }
  }

  /**
   * Records that mention a record.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function mentionsOf(EntityInterface $entity): array {
    if (!$this->ready()) {
      return [];
    }
    $rows = $this->database->select('divengine_reference', 'r')
      ->fields('r', ['source_type', 'source_id'])
      ->condition('r.target_type', $entity->getEntityTypeId())
      ->condition('r.target_id', $entity->id())
      ->execute()
      ->fetchAll();
    $sources = [];
    foreach ($rows as $row) {
      if ($this->entityTypeManager->hasDefinition($row->source_type) && ($source = $this->entityTypeManager->getStorage($row->source_type)->load($row->source_id))) {
        $sources[] = $source;
      }
    }
    return $sources;
  }


  /**
   * Whether the mentions table exists (it does once the module is
   * installed or updated).
   */
  protected function ready(): bool {
    return $this->ready ??= $this->database->schema()->tableExists('divengine_reference');
  }

}
