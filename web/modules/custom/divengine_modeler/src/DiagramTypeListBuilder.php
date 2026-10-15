<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists diagram types with their notation.
 */
class DiagramTypeListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Diagram type'),
      'notation' => $this->t('Notation'),
      'description' => $this->t('Description'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_modeler\Entity\DiagramType $entity */
    $options = \Drupal::service('plugin.manager.modeler_notation')->getOptions();
    return [
      'label' => $entity->label(),
      'notation' => $options[$entity->getNotationId()] ?? $this->t('Missing (@id)', ['@id' => $entity->getNotationId()]),
      'description' => $entity->getDescription(),
    ] + parent::buildRow($entity);
  }

}
