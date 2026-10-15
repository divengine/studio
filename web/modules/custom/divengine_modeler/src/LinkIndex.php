<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * Query index of element links (ADR-0005).
 *
 * The diagram document is the truth; this table is rebuilt from it on every
 * save and exists for backlinks, diagram graphs and integrity checks.
 */
class LinkIndex {

  const TABLE = 'diagram_element_link';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Replaces the rows of a diagram with the links of its source.
   */
  public function rebuild(DiagramInterface $diagram): void {
    $links = $diagram->getNotation()->extractLinks($diagram->getSource(), $diagram->getBindings());
    $this->deleteDiagram((int) $diagram->id());
    if (!$links) {
      return;
    }
    $insert = $this->database->insert(static::TABLE)
      ->fields(['diagram_id', 'revision_id', 'element_id', 'target_type', 'target_id', 'label', 'broken']);
    foreach ($links as $link) {
      $target = static::parseUri($link['entity']);
      if (!$target) {
        continue;
      }
      $insert->values([
        'diagram_id' => $diagram->id(),
        'revision_id' => $diagram->getRevisionId(),
        'element_id' => mb_substr($link['element'], 0, 255),
        'target_type' => $target[0],
        'target_id' => $target[1],
        'label' => mb_substr($link['label'] ?? '', 0, 255),
        'broken' => (int) !$this->exists($target[0], $target[1]),
      ]);
    }
    $insert->execute();
  }

  public function deleteDiagram(int $diagram_id): void {
    $this->database->delete(static::TABLE)->condition('diagram_id', $diagram_id)->execute();
  }

  /**
   * Flags the links pointing to a deleted entity; documents stay untouched.
   */
  public function markBroken(string $entity_type_id, string $id): void {
    $diagrams = $this->diagramsLinkingTo($entity_type_id, $id);
    if (!$diagrams) {
      return;
    }
    $this->database->update(static::TABLE)
      ->fields(['broken' => 1])
      ->condition('target_type', $entity_type_id)
      ->condition('target_id', $id)
      ->execute();
    Cache::invalidateTags(array_map(static fn (int $diagram): string => 'diagram:' . $diagram, $diagrams));
  }

  /**
   * The links of a diagram.
   *
   * @return object[]
   *   Rows ordered by element.
   */
  public function linksOf(int $diagram_id): array {
    return $this->database->select(static::TABLE, 'l')
      ->fields('l')
      ->condition('diagram_id', $diagram_id)
      ->orderBy('element_id')
      ->execute()
      ->fetchAll();
  }

  /**
   * IDs of diagrams with an element linked to an entity.
   *
   * @return int[]
   */
  public function diagramsLinkingTo(string $entity_type_id, string $id): array {
    $ids = $this->database->select(static::TABLE, 'l')
      ->fields('l', ['diagram_id'])
      ->condition('target_type', $entity_type_id)
      ->condition('target_id', $id)
      ->distinct()
      ->orderBy('diagram_id')
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * Splits an entity:<type>/<id> URI.
   *
   * @return array{0: string, 1: string}|null
   */
  public static function parseUri(string $uri): ?array {
    if (!ModelerNotationBase::isEntityUri($uri)) {
      return NULL;
    }
    [$type, $id] = explode('/', substr($uri, 7), 2);
    return [$type, $id];
  }

  protected function exists(string $entity_type_id, string $id): bool {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return FALSE;
    }
    // Loading (not querying) lets storage discard IDs of the wrong type, which
    // would otherwise abort the surrounding transaction on PostgreSQL.
    return $this->entityTypeManager->getStorage($entity_type_id)->load($id) !== NULL;
  }

}
