<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists process configuration in its order, with its code and status.
 */
class ProcessConfigListBuilder extends DraggableListBuilder {

  public function getFormId(): string {
    return $this->entityTypeId . '_list';
  }

  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'code' => $this->t('Code'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    return [
      'label' => $entity->label(),
      'code' => ['#markup' => $entity->id()],
      'status' => ['#markup' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled')],
    ] + parent::buildRow($entity);
  }

}
