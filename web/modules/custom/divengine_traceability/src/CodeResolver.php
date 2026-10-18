<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Finds any Studio record by its code: REQ-0001, UC-0002, BILL-0003,
 * KB-0004, DEV-0001, BILL (a project key)…
 *
 * Modules add the code fields of their records with
 * hook_divengine_code_fields_alter().
 */
class CodeResolver {

  /**
   * Entity type => code field, for the records that have one.
   */
  const FIELDS = [
    'artifact' => 'artifact_key',
    'development' => 'code',
    'change_request' => 'code',
    'baseline' => 'code',
    'qa_test_plan' => 'code',
    'qa_test_suite' => 'code',
    'qa_test_case' => 'code',
    'qa_test_run' => 'code',
    'qa_issue' => 'code',
    'project' => 'project_key',
    'project_task' => 'code',
    'node' => 'kb_code',
  ];

  /**
   * Pattern of a reference in text: [[CODE]] or [[CODE|label]].
   */
  const PATTERN = '/\[\[([A-Za-z][A-Za-z0-9]*(?:-\d+)?)(?:\|([^\]\n]{1,120}))?\]\]/';

  protected ?array $fields = NULL;

  /**
   * Resolved codes, for the request.
   *
   * @var array<string, array{0: string, 1: int}|false>
   */
  protected array $cache = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * @return array<string, string>
   */
  public function fields(): array {
    if ($this->fields === NULL) {
      $fields = static::FIELDS;
      $this->moduleHandler->alter('divengine_code_fields', $fields);
      $this->fields = array_filter($fields, fn (string $id) => $this->entityTypeManager->hasDefinition($id), ARRAY_FILTER_USE_KEY);
    }
    return $this->fields;
  }

  /**
   * The record with a code, or NULL. Access is not checked here.
   */
  public function resolve(string $code): ?ContentEntityInterface {
    $key = mb_strtoupper(trim($code));
    if (!array_key_exists($key, $this->cache)) {
      $this->cache[$key] = FALSE;
      foreach ($this->fields() as $entity_type_id => $field) {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
        $ids = $storage->getQuery()->accessCheck(FALSE)->condition($field, $key)->range(0, 1)->execute();
        if ($ids) {
          $this->cache[$key] = [$entity_type_id, (int) reset($ids)];
          break;
        }
      }
    }
    if (!$this->cache[$key]) {
      return NULL;
    }
    [$entity_type_id, $id] = $this->cache[$key];
    return $this->entityTypeManager->getStorage($entity_type_id)->load($id);
  }

  /**
   * The codes referenced in a text.
   *
   * @return string[]
   */
  public function codesIn(string $text): array {
    preg_match_all(static::PATTERN, $text, $matches);
    return array_values(array_unique(array_map('mb_strtoupper', $matches[1])));
  }

  /**
   * The code of a record, if it has one.
   */
  public function codeOf(ContentEntityInterface $entity): ?string {
    $field = $this->fields()[$entity->getEntityTypeId()] ?? NULL;
    return $field && $entity->hasField($field) && !$entity->get($field)->isEmpty() ? (string) $entity->get($field)->value : NULL;
  }

}
