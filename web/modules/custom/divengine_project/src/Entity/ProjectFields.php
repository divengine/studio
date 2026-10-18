<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Base field definitions shared by projects and tasks.
 *
 * Every field is shown in forms and displays unless stated otherwise;
 * $weight orders it.
 */
final class ProjectFields {

  public static function string(TranslatableMarkup $label, int $weight, bool $required = FALSE, int $max_length = 255): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel($label)
      ->setRequired($required)
      ->setSetting('max_length', $max_length)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * Formatted text (description), edited with the site's text formats.
   */
  public static function formatted(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('text_long')
      ->setLabel($label)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => $weight, 'settings' => ['rows' => 8]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  public static function date(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('datetime')
      ->setLabel($label)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  public static function user(TranslatableMarkup $label, int $weight, bool $multiple = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality($multiple ? FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED : 1)
      ->setDisplayOptions('form', ['type' => $multiple ? 'entity_reference_autocomplete_tags' : 'entity_reference_autocomplete', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  public static function reference(TranslatableMarkup $label, string $target_type, int $weight, bool $required = FALSE, bool $multiple = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setRequired($required)
      ->setSetting('target_type', $target_type)
      ->setSetting('handler', 'default')
      ->setCardinality($multiple ? FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED : 1)
      ->setDisplayOptions('form', ['type' => $multiple ? 'options_buttons' : 'options_select', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A list field with fixed values.
   */
  public static function list(TranslatableMarkup $label, array $values, string $default, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('list_string')
      ->setLabel($label)
      ->setRequired(TRUE)
      ->setDefaultValue($default)
      ->setSetting('allowed_values', array_map('strval', $values))
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

}
