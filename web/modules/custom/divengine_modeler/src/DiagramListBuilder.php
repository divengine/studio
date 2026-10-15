<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Fallback listing of diagrams; the Views listing replaces it when present.
 */
class DiagramListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'type' => $this->t('Type'),
      'changed' => $this->t('Changed'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_modeler\Entity\DiagramInterface $entity */
    return [
      'label' => $entity->toLink(),
      'type' => $entity->get('type')->entity?->label() ?? $entity->bundle(),
      'changed' => \Drupal::service('date.formatter')->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->access('update')) {
      $operations['modeler'] = [
        'title' => $this->t('Open modeler'),
        'weight' => -10,
        'url' => Url::fromRoute('entity.diagram.modeler', ['diagram' => $entity->id()]),
      ];
    }
    return $operations;
  }

}
