<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\EntityInterface;

/**
 * Lists Development Cases with their version and default flag.
 */
class DevelopmentCaseListBuilder extends ProcessConfigListBuilder {

  public function buildHeader(): array {
    $header = parent::buildHeader();
    return array_slice($header, 0, 2, TRUE) + [
      'version' => $this->t('Version'),
      'lines' => $this->t('Lines'),
      'default' => $this->t('Default'),
    ] + array_slice($header, 2, NULL, TRUE);
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\divengine_process\Entity\DevelopmentCase $entity */
    $row = parent::buildRow($entity);
    return array_slice($row, 0, 2, TRUE) + [
      'version' => ['#markup' => $entity->getVersion()],
      'lines' => ['#markup' => count($entity->getLines())],
      'default' => ['#markup' => $entity->isDefault() ? $this->t('Yes') : ''],
    ] + array_slice($row, 2, NULL, TRUE);
  }

}
