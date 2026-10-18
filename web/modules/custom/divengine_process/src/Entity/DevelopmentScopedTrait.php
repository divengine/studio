<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The reference to the Development a record belongs to.
 */
trait DevelopmentScopedTrait {

  public function getDevelopment(): ?DevelopmentInterface {
    return $this->get('development')->entity;
  }

  /**
   * The required "development" base field.
   */
  protected static function developmentFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Development'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'development')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -20,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

}
