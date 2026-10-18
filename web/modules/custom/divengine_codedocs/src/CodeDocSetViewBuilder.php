<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\divengine_codedocs\Controller\CodeDocsController;
use Drupal\divengine_codedocs\Entity\CodeDocSet;

/** Entity pages share the same renderer as the documentation catalog. */
class CodeDocSetViewBuilder extends EntityViewBuilder {

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if (!in_array($view_mode, ['full', 'default'], TRUE)) {
      return;
    }
    $controller = new CodeDocsController();
    foreach ($entities as $id => $entity) {
      foreach (array_keys($entity->getFieldDefinitions()) as $field) {
        unset($build[$id][$field]);
      }
      $build[$id] += $entity instanceof CodeDocSet ? $controller->overview($entity) : $controller->snapshot($entity);
      $build[$id]['#cache']['max-age'] = 0;
    }
  }

}
