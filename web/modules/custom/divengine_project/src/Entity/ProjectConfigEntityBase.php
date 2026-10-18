<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Base of the project configuration: stages and task types.
 */
abstract class ProjectConfigEntityBase extends ConfigEntityBase {

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
   * Enabled entities of a type, in their order.
   *
   * @return static[]
   */
  public static function loadOrdered(): array {
    $entities = array_filter(static::loadMultiple(), fn ($entity) => $entity->status());
    uasort($entities, fn ($a, $b) => [$a->getWeight(), $a->label()] <=> [$b->getWeight(), $b->label()]);
    return $entities;
  }

}
