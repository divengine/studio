<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Offers only records of the referencing entity's Development.
 *
 * Without a Development on the referencing entity, nothing is restricted;
 * the ProcessSameDevelopment constraint has the last word.
 */
#[EntityReferenceSelection(
  id: 'divengine_process_development',
  label: new TranslatableMarkup('Records of the same Development'),
  group: 'divengine_process_development',
  weight: 5,
  entity_types: ['artifact', 'iteration'],
)]
class DevelopmentSelection extends DefaultSelection {

  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $host = $this->configuration['entity'] ?? NULL;
    if ($host instanceof FieldableEntityInterface && $host->hasField('development') && ($development = $host->get('development')->target_id)) {
      $query->condition('development', $development);
    }
    if ($host instanceof FieldableEntityInterface && !$host->isNew() && $host->getEntityTypeId() === $this->configuration['target_type']) {
      $query->condition($host->getEntityType()->getKey('id'), $host->id(), '<>');
    }
    return $query;
  }

}
