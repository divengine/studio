<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Writes and reads the activity of QA records.
 *
 * Messages are stored as markup built with t() placeholders, so every value
 * in them is escaped.
 */
class QaLogger {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public function log(EntityInterface $entity, string|MarkupInterface $message): void {
    $this->entityTypeManager->getStorage('qa_log')->create([
      'target_type' => $entity->getEntityTypeId(),
      'target_id' => $entity->id(),
      'message' => (string) $message,
    ])->save();
  }

  /**
   * Fields whose changes are written to the activity of their record.
   */
  const TRACKED = [
    'qa_test_plan' => ['name', 'development', 'environment', 'stage', 'state'],
    'qa_test_suite' => ['name', 'plan', 'state'],
    'qa_test_case' => ['name', 'suite', 'priority', 'state'],
    'qa_test_step' => ['is_skippable'],
    'qa_test_run' => ['name', 'plan', 'tester', 'test_url', 'is_automated', 'state', 'last_reopened_by', 'last_reopened_at', 'last_reopen_reason'],
    'qa_test_execution' => ['run', 'test_case', 'is_case_skipped', 'state', 'actual_result', 'result_reason'],
  ];

  /**
   * Logs the creation of a record, or the changes of its tracked fields.
   */
  public function track(FieldableEntityInterface $entity): void {
    $fields = static::TRACKED[$entity->getEntityTypeId()] ?? [];
    if (!$fields) {
      return;
    }
    $original = $entity->getOriginal();
    if (!$original) {
      $this->log($entity, new TranslatableMarkup('@type created', ['@type' => $entity->getEntityType()->getLabel()]));
      return;
    }
    $changes = [];
    foreach ($fields as $field) {
      $old = $this->display($original, $field);
      $new = $this->display($entity, $field);
      if ($old !== $new) {
        $changes[] = new TranslatableMarkup('@field: @old → @new', [
          '@field' => $entity->getFieldDefinition($field)->getLabel(),
          '@old' => $old === '' ? '—' : $old,
          '@new' => $new === '' ? '—' : $new,
        ]);
      }
    }
    if ($changes) {
      $this->log($entity, implode('<br>', array_map('strval', $changes)));
    }
  }

  /**
   * A field value as people read it.
   */
  protected function display(FieldableEntityInterface $entity, string $field): string {
    $items = $entity->get($field);
    if ($items->isEmpty()) {
      return '';
    }
    $type = $items->getFieldDefinition()->getType();
    return match ($type) {
      'entity_reference' => (string) ($items->entity?->label() ?? $items->target_id),
      'boolean' => $items->value ? (string) new TranslatableMarkup('Yes') : (string) new TranslatableMarkup('No'),
      'list_string' => (string) (options_allowed_values($items->getFieldDefinition()->getFieldStorageDefinition(), $entity)[$items->value] ?? $items->value),
      'timestamp', 'created', 'changed' => \Drupal::service('date.formatter')->format((int) $items->value, 'short'),
      default => (string) $items->value,
    };
  }

  /**
   * The entries of a record, newest first.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function entries(EntityInterface $entity): array {
    $storage = $this->entityTypeManager->getStorage('qa_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_type', $entity->getEntityTypeId())
      ->condition('target_id', $entity->id())
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    return $storage->loadMultiple($ids);
  }

}
