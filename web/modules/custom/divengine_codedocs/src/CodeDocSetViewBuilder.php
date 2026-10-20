<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets and snapshots render as documentation pages (CodeDocsPages).
 */
class CodeDocSetViewBuilder extends EntityViewBuilder {

  protected CodeDocsPages $pages;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->pages = $container->get('divengine_codedocs.pages');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if (!in_array($view_mode, ['full', 'default'], TRUE)) {
      return;
    }
    foreach ($entities as $id => $entity) {
      foreach (array_keys($entity->getFieldDefinitions()) as $field) {
        if ($field !== 'thread') {
          unset($build[$id][$field]);
        }
      }
      $page = $entity instanceof CodeDocSet ? $this->pages->set($entity) : $this->pages->snapshot($entity);
      CacheableMetadata::createFromRenderArray($build[$id])->merge(CacheableMetadata::createFromRenderArray($page))->applyTo($page);
      $build[$id] = $page + $build[$id];
    }
  }

}
