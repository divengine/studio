<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Shared shape of the process configuration: code, name, description, order.
 *
 * The machine name is the stable code (for example "inception"); disabling an
 * entity is the equivalent of archiving it.
 */
abstract class ProcessConfigEntityBase extends ConfigEntityBase {

  protected $id;

  protected $label;

  protected string $description = '';

  protected int $weight = 0;

  public function getDescription(): string {
    return $this->description;
  }

  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Adds a config dependency on each referenced process entity that exists.
   *
   * @param string $entity_type_id
   *   The referenced config entity type.
   * @param string[] $ids
   *   The referenced machine names; empty values are skipped.
   */
  protected function addReferenceDependencies(string $entity_type_id, array $ids): void {
    $ids = array_filter($ids);
    if (!$ids) {
      return;
    }
    $storage = $this->entityTypeManager()->getStorage($entity_type_id);
    foreach ($storage->loadMultiple($ids) as $entity) {
      $this->addDependency('config', $entity->getConfigDependencyName());
    }
  }

}
