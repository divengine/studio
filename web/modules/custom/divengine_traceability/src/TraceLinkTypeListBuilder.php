<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists trace link types, ordered by weight.
 */
class TraceLinkTypeListBuilder extends DraggableListBuilder {

  public function getFormId(): string {
    return 'trace_link_type_list';
  }

  public function buildHeader(): array {
    return [
      'label' => $this->t('Type'),
      'inverse_label' => $this->t('Inverse'),
      'ends' => $this->t('Allowed ends'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkType $entity */
    $describe = fn (array $rules): string => $rules ? implode(', ', $rules) : (string) $this->t('any');
    $ends = $describe($entity->getSourceRules()) . ' → ' . $describe($entity->getTargetRules());
    if ($entity->requiresSameKind()) {
      $ends .= ' (' . $this->t('same kind') . ')';
    }
    return [
      'label' => $entity->label(),
      'inverse_label' => ['#markup' => $entity->getInverseLabel()],
      'ends' => ['#markup' => $ends],
    ] + parent::buildRow($entity);
  }

}
