<?php

declare(strict_types=1);

namespace Drupal\divengine_diagram_embed;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Tracks where diagrams are embedded.
 *
 * The usage of a host is rebuilt from its text fields every time its default
 * revision is saved: draft revisions waiting for review do not count, since
 * readers do not see them. A diagram is referenced by UUID in the text and by
 * ID in the table.
 */
class DiagramUsage {

  const TABLE = 'diagram_usage';

  /**
   * Field types whose values are formatted text.
   */
  const TEXT_FIELD_TYPES = ['text', 'text_long', 'text_with_summary'];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected EntityLastInstalledSchemaRepositoryInterface $installedSchema,
  ) {}

  /**
   * The cache tag of the usage list of one diagram.
   */
  public static function cacheTag(int $diagram_id): string {
    return 'diagram_usage:' . $diagram_id;
  }

  /**
   * The diagram UUIDs embedded in an HTML fragment, in order of appearance.
   *
   * @return string[]
   *   One entry per embed, so a UUID embedded twice appears twice.
   */
  public static function uuidsIn(string $html): array {
    if (stripos($html, '<drupal-diagram') === FALSE) {
      return [];
    }
    $uuids = [];
    foreach (Html::load($html)->getElementsByTagName('drupal-diagram') as $element) {
      $uuid = trim($element->getAttribute('data-diagram-uuid'));
      if ($uuid !== '') {
        $uuids[] = $uuid;
      }
    }
    return $uuids;
  }

  /**
   * Rebuilds the usage of a host from its default revision.
   */
  public function track(ContentEntityInterface $host): void {
    if (!$host->isDefaultRevision() || $host->getEntityTypeId() === 'diagram') {
      return;
    }
    $fields = $this->textFields($host);
    if (!$fields) {
      // Users, diagrams, trace links… cannot embed anything.
      return;
    }
    $rows = [];
    $embeds = [];
    foreach ($host->getTranslationLanguages() as $langcode => $language) {
      $translation = $host->getTranslation($langcode);
      foreach ($fields as $field_name) {
        foreach ($translation->get($field_name) as $item) {
          foreach (['value', 'summary'] as $property) {
            $text = $item->getValue()[$property] ?? '';
            foreach (static::uuidsIn((string) $text) as $uuid) {
              $embeds[] = [$uuid, $field_name, $langcode];
            }
          }
        }
      }
    }
    $ids = $this->diagramIds(array_unique(array_column($embeds, 0)));
    foreach ($embeds as [$uuid, $field_name, $langcode]) {
      if (isset($ids[$uuid])) {
        $key = $ids[$uuid] . ':' . $field_name . ':' . $langcode;
        $rows[$key] ??= ['diagram_id' => $ids[$uuid], 'field_name' => $field_name, 'langcode' => $langcode, 'count' => 0];
        $rows[$key]['count']++;
      }
    }
    $this->replace($host->getEntityTypeId(), (string) $host->id(), array_values($rows));
  }

  /**
   * Forgets every embed held by a host.
   */
  public function forgetHost(string $host_type, string $host_id): void {
    $this->replace($host_type, $host_id, []);
  }

  /**
   * Forgets the embeds held by one translation of a host.
   */
  public function forgetTranslation(string $host_type, string $host_id, string $langcode): void {
    $affected = $this->database->select(static::TABLE, 'u')
      ->fields('u', ['diagram_id'])
      ->condition('host_type', $host_type)
      ->condition('host_id', $host_id)
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchCol();
    $this->database->delete(static::TABLE)
      ->condition('host_type', $host_type)
      ->condition('host_id', $host_id)
      ->condition('langcode', $langcode)
      ->execute();
    $this->invalidate($affected);
  }

  /**
   * Forgets every embed of a deleted diagram.
   */
  public function forgetDiagram(int $diagram_id): void {
    $this->database->delete(static::TABLE)->condition('diagram_id', $diagram_id)->execute();
    $this->invalidate([$diagram_id]);
  }

  /**
   * The hosts embedding a diagram.
   *
   * @return object[]
   *   Rows with host_type, host_id, field_name, langcode and count.
   */
  public function usagesOf(int $diagram_id): array {
    return $this->database->select(static::TABLE, 'u')
      ->fields('u', ['host_type', 'host_id', 'field_name', 'langcode', 'count'])
      ->condition('diagram_id', $diagram_id)
      ->orderBy('host_type')
      ->orderBy('host_id')
      ->orderBy('field_name')
      ->orderBy('langcode')
      ->execute()
      ->fetchAll();
  }

  /**
   * The diagrams embedded by a host, with their total count.
   *
   * @return int[]
   *   Embed count keyed by diagram ID.
   */
  public function diagramsIn(string $host_type, string $host_id): array {
    $query = $this->database->select(static::TABLE, 'u')
      ->condition('host_type', $host_type)
      ->condition('host_id', $host_id)
      ->groupBy('diagram_id')
      ->fields('u', ['diagram_id']);
    $query->addExpression('SUM(count)', 'total');
    return array_map('intval', $query->execute()->fetchAllKeyed());
  }

  /**
   * Rebuilds the usage of every entity that has a formatted text field.
   *
   * @return int
   *   The number of hosts scanned.
   */
  public function rebuildAll(): int {
    $this->database->truncate(static::TABLE)->execute();
    $scanned = 0;
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      $skip = $entity_type_id === 'diagram'
        || !$entity_type->entityClassImplements(FieldableEntityInterface::class)
        || !$this->installedSchema->getLastInstalledDefinition($entity_type_id)
        || !$this->entityTypeHasTextFields($entity_type_id);
      if ($skip) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
      foreach (array_chunk($ids, 50) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $host) {
          $this->track($host);
          $scanned++;
        }
        $storage->resetCache($chunk);
      }
    }
    return $scanned;
  }

  /**
   * The formatted text fields of a host.
   *
   * @return string[]
   */
  protected function textFields(FieldableEntityInterface $host): array {
    $names = [];
    foreach ($host->getFieldDefinitions() as $name => $definition) {
      if (in_array($definition->getType(), static::TEXT_FIELD_TYPES, TRUE)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  protected function entityTypeHasTextFields(string $entity_type_id): bool {
    foreach ($this->entityFieldManager->getFieldStorageDefinitions($entity_type_id) as $definition) {
      if (in_array($definition->getType(), static::TEXT_FIELD_TYPES, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Diagram IDs keyed by UUID, for the UUIDs that exist.
   *
   * @return int[]
   */
  protected function diagramIds(array $uuids): array {
    if (!$uuids) {
      return [];
    }
    $ids = [];
    $storage = $this->entityTypeManager->getStorage('diagram');
    foreach ($storage->loadByProperties(['uuid' => $uuids]) as $diagram) {
      $ids[$diagram->uuid()] = (int) $diagram->id();
    }
    return $ids;
  }

  /**
   * Replaces the rows of one host and invalidates every affected diagram.
   */
  protected function replace(string $host_type, string $host_id, array $rows): void {
    $before = $this->database->select(static::TABLE, 'u')
      ->fields('u', ['diagram_id', 'field_name', 'langcode', 'count'])
      ->condition('host_type', $host_type)
      ->condition('host_id', $host_id)
      ->execute()
      ->fetchAll(FetchAs::Associative);
    $normalize = static function (array $list): array {
      $keyed = [];
      foreach ($list as $row) {
        $keyed[$row['diagram_id'] . ':' . $row['field_name'] . ':' . $row['langcode']] = (int) $row['count'];
      }
      ksort($keyed);
      return $keyed;
    };
    if ($normalize($before) === $normalize($rows)) {
      return;
    }
    $transaction = $this->database->startTransaction();
    $this->database->delete(static::TABLE)
      ->condition('host_type', $host_type)
      ->condition('host_id', $host_id)
      ->execute();
    if ($rows) {
      $insert = $this->database->insert(static::TABLE)
        ->fields(['diagram_id', 'host_type', 'host_id', 'field_name', 'langcode', 'count']);
      foreach ($rows as $row) {
        $insert->values([
          'diagram_id' => $row['diagram_id'],
          'host_type' => $host_type,
          'host_id' => $host_id,
          'field_name' => $row['field_name'],
          'langcode' => $row['langcode'],
          'count' => $row['count'],
        ]);
      }
      $insert->execute();
    }
    unset($transaction);
    $this->invalidate(array_merge(array_column($before, 'diagram_id'), array_column($rows, 'diagram_id')));
  }

  protected function invalidate(array $diagram_ids): void {
    $tags = array_map(static fn ($id): string => static::cacheTag((int) $id), array_unique($diagram_ids));
    if ($tags) {
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }
  }

}
