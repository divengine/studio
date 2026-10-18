<?php

declare(strict_types=1);

namespace Drupal\divengine_thread;

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Sets up and reads the discussion ("thread") of Studio records.
 *
 * Each record type gets a comment type "thread_<entity type>" whose
 * comments have an optional body and private attachments, and a "thread"
 * comment field on its bundles. Modules declare their record types with
 * hook_divengine_thread_types_alter(); a type that already has a comment
 * field declares it instead of "thread".
 */
class ThreadManager implements DestructableInterface {

  /**
   * Record types and bundles (NULL = every bundle) that get a thread.
   */
  const TYPES = [
    'artifact' => NULL,
    'diagram' => NULL,
    'development' => NULL,
    'iteration' => NULL,
    'milestone' => NULL,
    'process_activity' => NULL,
    'change_request' => NULL,
    'baseline' => NULL,
    'qa_test_plan' => NULL,
    'qa_test_suite' => NULL,
    'qa_test_case' => NULL,
    'qa_test_run' => NULL,
    'qa_test_execution' => NULL,
    'qa_issue' => NULL,
    'project' => NULL,
  ];

  /**
   * The threaded types, once collected.
   */
  protected ?array $types = NULL;

  /**
   * Whether a sync is due at the end of the request.
   */
  protected bool $syncScheduled = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityDisplayRepositoryInterface $displayRepository,
    protected ModuleHandlerInterface $moduleHandler,
    protected Connection $database,
  ) {}

  /**
   * The record types with a thread: entity type => [bundles|NULL, field, comment type].
   *
   * @return array<string, array{bundles: ?array, field: string, comment_type: string}>
   */
  public function types(): array {
    if ($this->types !== NULL) {
      return $this->types;
    }
    $types = [];
    foreach (static::TYPES as $entity_type_id => $bundles) {
      $types[$entity_type_id] = ['bundles' => $bundles, 'field' => 'thread', 'comment_type' => 'thread_' . $entity_type_id];
    }
    $this->moduleHandler->alter('divengine_thread_types', $types);
    return $this->types = array_filter($types, fn (string $id) => $this->entityTypeManager->hasDefinition($id), ARRAY_FILTER_USE_KEY);
  }

  /**
   * The thread field of a record, if it has one.
   */
  public function field(ContentEntityInterface $entity): ?string {
    $type = $this->types()[$entity->getEntityTypeId()] ?? NULL;
    if (!$type || ($type['bundles'] !== NULL && !in_array($entity->bundle(), $type['bundles'], TRUE))) {
      return NULL;
    }
    return $entity->hasField($type['field']) ? $type['field'] : NULL;
  }

  /**
   * Creates whatever is missing: comment types, their fields, the thread
   * fields on the record bundles. Safe to run again.
   */
  public function sync(): void {
    foreach ($this->types() as $entity_type_id => $type) {
      $this->ensureCommentType($type['comment_type'], $entity_type_id);
      $bundles = $type['bundles'] ?? array_keys($this->bundleInfo->getBundleInfo($entity_type_id));
      foreach ($bundles as $bundle) {
        $this->ensureHostField($entity_type_id, $bundle, $type['field'], $type['comment_type']);
      }
    }
  }

  /**
   * Syncs at the end of the request (see destruct()).
   */
  public function scheduleSync(): void {
    $this->syncScheduled = TRUE;
  }

  public function destruct(): void {
    if ($this->syncScheduled) {
      $this->syncScheduled = FALSE;
      $this->types = NULL;
      $this->sync();
    }
  }

  /**
   * Files posted in a record's thread, newest first.
   *
   * @return array<int, array{fid: int, cid: int, uid: int, created: int}>
   */
  public function attachments(ContentEntityInterface $entity, string $field): array {
    if (!$this->database->schema()->tableExists('comment__thread_files')) {
      return [];
    }
    $query = $this->database->select('comment_field_data', 'c');
    $query->join('comment__thread_files', 'f', 'f.entity_id = c.cid');
    $query->fields('f', ['thread_files_target_id']);
    $query->fields('c', ['cid', 'uid', 'created']);
    $query->condition('c.entity_type', $entity->getEntityTypeId())
      ->condition('c.entity_id', $entity->id())
      ->condition('c.field_name', $field)
      ->condition('c.status', 1)
      ->orderBy('c.created', 'DESC')
      ->orderBy('f.delta');
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = ['fid' => (int) $row->thread_files_target_id, 'cid' => (int) $row->cid, 'uid' => (int) $row->uid, 'created' => (int) $row->created];
    }
    return $rows;
  }

  protected function ensureCommentType(string $id, string $target_entity_type_id): void {
    $storage = $this->entityTypeManager->getStorage('comment_type');
    if (!$storage->load($id)) {
      $label = $this->entityTypeManager->getDefinition($target_entity_type_id)->getLabel();
      $storage->create([
        'id' => $id,
        'label' => 'Discussion on ' . $label,
        'target_entity_type_id' => $target_entity_type_id,
        'description' => 'Comments and attachments on a ' . $label . '.',
      ])->save();
    }
    $this->ensureCommentFields($id);
  }

  /**
   * An optional body and private attachments on a comment type.
   */
  public function ensureCommentFields(string $comment_type): void {
    if (!FieldStorageConfig::loadByName('comment', 'comment_body')) {
      FieldStorageConfig::create(['field_name' => 'comment_body', 'entity_type' => 'comment', 'type' => 'text_long'])->save();
    }
    if (!FieldConfig::loadByName('comment', $comment_type, 'comment_body')) {
      FieldConfig::create(['field_storage' => FieldStorageConfig::loadByName('comment', 'comment_body'), 'bundle' => $comment_type, 'label' => 'Message', 'required' => FALSE])->save();
    }
    if (!FieldStorageConfig::loadByName('comment', 'thread_files')) {
      FieldStorageConfig::create([
        'field_name' => 'thread_files',
        'entity_type' => 'comment',
        'type' => 'file',
        'cardinality' => -1,
        'settings' => ['uri_scheme' => 'private', 'target_type' => 'file', 'display_field' => FALSE, 'display_default' => TRUE],
      ])->save();
    }
    if (!FieldConfig::loadByName('comment', $comment_type, 'thread_files')) {
      FieldConfig::create([
        'field_storage' => FieldStorageConfig::loadByName('comment', 'thread_files'),
        'bundle' => $comment_type,
        'label' => 'Attachments',
        'settings' => [
          'file_extensions' => 'png jpg jpeg gif webp svg pdf txt md log csv json xml yaml yml zip doc docx xls xlsx ppt pptx odt ods odp bpmn puml drawio mp4 webm har',
          'file_directory' => 'thread/[date:custom:Y]-[date:custom:m]',
          'max_filesize' => '',
          'description_field' => FALSE,
        ],
      ])->save();
    }
    $this->displayRepository->getFormDisplay('comment', $comment_type)
      ->setComponent('comment_body', ['type' => 'text_textarea', 'weight' => 0, 'settings' => ['rows' => 4]])
      ->setComponent('thread_files', ['type' => 'file_generic', 'weight' => 5])
      ->removeComponent('subject')
      ->setComponent('author', ['weight' => -2])
      ->save();
    $this->displayRepository->getViewDisplay('comment', $comment_type)
      ->setComponent('comment_body', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0])
      ->setComponent('thread_files', ['type' => 'file_default', 'label' => 'hidden', 'weight' => 5])
      ->setComponent('links', ['weight' => 100])
      ->save();
  }

  protected function ensureHostField(string $entity_type_id, string $bundle, string $field_name, string $comment_type): void {
    if (!FieldStorageConfig::loadByName($entity_type_id, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'type' => 'comment',
        'settings' => ['comment_type' => $comment_type],
      ])->save();
    }
    if (!FieldConfig::loadByName($entity_type_id, $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'bundle' => $bundle,
        'label' => 'Discussion',
        'default_value' => [['status' => CommentItemInterface::OPEN, 'cid' => 0, 'last_comment_timestamp' => 0, 'last_comment_name' => '', 'last_comment_uid' => 0, 'comment_count' => 0]],
        'settings' => ['default_mode' => 1, 'per_page' => 50, 'anonymous' => 0, 'form_location' => TRUE, 'preview' => 0],
      ])->save();
      // The thread is rendered by divengine_thread_entity_view(), not by
      // the display, so pages that build their own layout show it too.
      $this->displayRepository->getViewDisplay($entity_type_id, $bundle)->removeComponent($field_name)->save();
      $this->displayRepository->getFormDisplay($entity_type_id, $bundle)->removeComponent($field_name)->save();
    }
  }

}
