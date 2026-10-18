<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists the Developments the user sees, newest code first.
 */
class DevelopmentListBuilder extends EntityListBuilder {

  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('code', 'DESC');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  public function buildHeader(): array {
    return [
      'code' => $this->t('Code'),
      'name' => $this->t('Name'),
      'state' => $this->t('State'),
      'phase' => $this->t('Current phase'),
      'responsible' => $this->t('Responsible'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_process\Entity\Development $entity */
    return [
      'code' => $entity->getCode(),
      'name' => $entity->toLink((string) $entity->get('name')->value),
      'state' => $entity->getStateLabel(),
      'phase' => $entity->get('current_phase')->entity?->label() ?? '',
      'responsible' => $entity->get('responsible')->entity?->getDisplayName() ?? '',
    ] + parent::buildRow($entity);
  }

}
