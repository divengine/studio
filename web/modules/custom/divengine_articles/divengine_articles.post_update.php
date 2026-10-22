<?php

/**
 * @file
 * Post update functions for Divengine Articles.
 */

declare(strict_types=1);

/**
 * Gives every sub-article the Development of its parent.
 *
 * Until now an article that changed Development left the articles below it
 * in the previous one, visible to its people.
 */
function divengine_articles_post_update_sub_articles_follow_parent(array &$sandbox): string {
  $database = \Drupal::database();
  if (!isset($sandbox['ids'])) {
    // Sub-articles whose Development differs from their parent's.
    $query = $database->select('node__kb_parent', 'p');
    $query->leftJoin('node__kb_development', 'd', 'd.entity_id = p.entity_id');
    $query->leftJoin('node__kb_development', 'pd', 'pd.entity_id = p.kb_parent_target_id');
    $query->fields('p', ['entity_id']);
    $query->where('COALESCE(d.kb_development_target_id, 0) <> COALESCE(pd.kb_development_target_id, 0)');
    $sandbox['ids'] = array_map('intval', $query->execute()->fetchCol());
    $sandbox['total'] = count($sandbox['ids']);
  }
  // Saving the topmost one moves the articles below it too.
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  foreach (array_splice($sandbox['ids'], 0, 20) as $id) {
    $article = $storage->load($id);
    $parent = $article?->get('kb_parent')->entity;
    if ($parent && (string) $article->get('kb_development')->target_id !== (string) $parent->get('kb_development')->target_id) {
      $article->setNewRevision(FALSE);
      $article->save();
    }
  }
  $sandbox['#finished'] = $sandbox['ids'] ? 1 - count($sandbox['ids']) / $sandbox['total'] : 1;
  return (string) t('@count sub-articles now belong to the Development of their parent.', ['@count' => $sandbox['total']]);
}
