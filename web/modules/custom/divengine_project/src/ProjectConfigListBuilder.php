<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\divengine_project\Entity\ProjectStage;

/**
 * Lists stages and task types in their order.
 */
class ProjectConfigListBuilder extends DraggableListBuilder {

  public function getFormId(): string {
    return $this->entityTypeId . '_list';
  }

  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'code' => $this->t('Code'),
      'meaning' => $this->entityTypeId === 'project_stage' ? $this->t('Category') : $this->t('Color'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    $meaning = $entity instanceof ProjectStage
      ? ['#markup' => ProjectStage::categories()[$entity->getCategory()] ?? $entity->getCategory()]
      : ['#type' => 'html_tag', '#tag' => 'span', '#value' => $entity->getColor(), '#attributes' => ['style' => 'border-left:1rem solid ' . $entity->getColor() . ';padding-left:.5rem']];
    return [
      'label' => $entity->label(),
      'code' => ['#markup' => $entity->id()],
      'meaning' => $meaning,
      'status' => ['#markup' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled')],
    ] + parent::buildRow($entity);
  }

}
