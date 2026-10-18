<?php

declare(strict_types=1);

namespace Drupal\divengine_articles;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The hierarchy of knowledge articles.
 */
class ArticleTree {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Visible articles matching conditions, in reading order.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function articles(array $conditions = []): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'knowledge_article');
    foreach ($conditions as $field => $value) {
      if ($value === NULL) {
        $query->notExists($field);
      }
      else {
        $query->condition($field, $value, is_array($value) ? 'IN' : '=');
      }
    }
    $articles = $storage->loadMultiple($query->execute());
    uasort($articles, fn (NodeInterface $a, NodeInterface $b) => [(int) $a->get('kb_weight')->value, mb_strtolower($a->label())] <=> [(int) $b->get('kb_weight')->value, mb_strtolower($b->label())]);
    return $articles;
  }

  /**
   * The direct sub-articles of an article.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function children(NodeInterface $article): array {
    return $this->articles(['kb_parent' => $article->id()]);
  }

  /**
   * The ancestors of an article, outermost first.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function ancestors(NodeInterface $article): array {
    $chain = [];
    $seen = [(int) $article->id() => TRUE];
    for ($node = $article->get('kb_parent')->entity; $node && !isset($seen[(int) $node->id()]); $node = $node->get('kb_parent')->entity) {
      $seen[(int) $node->id()] = TRUE;
      array_unshift($chain, $node);
    }
    return $chain;
  }

  /**
   * IDs of every article below one.
   *
   * @return int[]
   */
  public function descendantIds(int $id): array {
    $ids = [];
    $frontier = [$id];
    while ($frontier) {
      $children = array_map('intval', $this->database->select('node__kb_parent', 'p')->fields('p', ['entity_id'])->condition('p.kb_parent_target_id', $frontier, 'IN')->execute()->fetchCol());
      $frontier = array_values(array_diff($children, $ids, [$id]));
      $ids = array_merge($ids, $frontier);
    }
    return $ids;
  }

  /**
   * A nested list of articles and their sub-articles.
   *
   * @param \Drupal\node\NodeInterface[] $roots
   */
  public function tree(array $roots, ?int $current = NULL, int $depth = 0): array {
    $items = [];
    foreach ($roots as $article) {
      $children = $depth < 8 ? $this->children($article) : [];
      $item = [
        '#type' => 'link',
        '#title' => $article->label(),
        '#url' => $article->toUrl(),
        '#attributes' => ['class' => array_filter(['dv-kb-tree__link', (int) $article->id() === $current ? 'is-current' : NULL])],
      ];
      $items[] = $children ? ['link' => $item, 'children' => $this->tree($children, $current, $depth + 1)] : $item;
    }
    return ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['dv-kb-tree']]];
  }

}
