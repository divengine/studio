<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Entity\DevelopmentScopedTrait;

/**
 * Records of a test plan: they belong to the plan's Development.
 *
 * The Development is copied from the parent record on save, so lists and
 * queries filter by it like any other Development record.
 */
trait QaScopedTrait {

  use DevelopmentScopedTrait;

  /**
   * The "development" base field, set from the parent record.
   */
  protected static function derivedDevelopmentField(): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Development'))
      ->setSetting('target_type', 'development');
  }

  /**
   * Copies the Development of a parent record.
   */
  protected function inheritDevelopment(?\Drupal\Core\Entity\FieldableEntityInterface $parent): void {
    if ($parent && $parent->hasField('development')) {
      $this->set('development', $parent->get('development')->target_id);
    }
  }

  /**
   * Assigns the next code with a prefix when none was given.
   */
  protected function assignCode(string $prefix): void {
    $code = trim((string) $this->get('code')->value);
    if ($code === '' || $code === 'New') {
      $this->set('code', \Drupal::service('divengine_core.sequence')->next($prefix));
    }
    else {
      $this->set('code', $code);
    }
  }

  public function label() {
    $code = (string) $this->get('code')->value;
    $name = (string) $this->get('name')->value;
    return $code !== '' ? "[$code] $name" : $name;
  }

}
