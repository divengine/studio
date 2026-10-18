<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Vocabulary;

/**
 * Base field definitions shared by the process content entities.
 *
 * Every field is shown in forms and displays unless stated otherwise;
 * $weight orders it.
 */
final class ProcessFields {

  public static function string(TranslatableMarkup $label, int $weight, bool $required = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel($label)
      ->setRequired($required)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  public static function text(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('string_long')
      ->setLabel($label)
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => $weight, 'settings' => ['rows' => 3]])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  public static function date(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('datetime')
      ->setLabel($label)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'datetime_default', 'weight' => $weight, 'settings' => ['format_type' => 'html_date']])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A reference to one user, or to many with $multiple.
   */
  public static function user(TranslatableMarkup $label, int $weight, bool $multiple = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality($multiple ? FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED : 1)
      ->setDisplayOptions('form', ['type' => $multiple ? 'entity_reference_autocomplete_tags' : 'entity_reference_autocomplete', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => $weight, 'settings' => ['link' => FALSE]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A reference to another entity, shown as a select.
   */
  public static function reference(TranslatableMarkup $label, string $target_type, int $weight, bool $required = FALSE): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setRequired($required)
      ->setSetting('target_type', $target_type)
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A list field whose values come from the vocabulary.
   *
   * State fields are not in forms: they change through transitions.
   */
  public static function list(TranslatableMarkup $label, string $default, int $weight, bool $in_form = TRUE): BaseFieldDefinition {
    $field = BaseFieldDefinition::create('list_string')
      ->setLabel($label)
      ->setRequired(TRUE)
      ->setDefaultValue($default)
      ->setSetting('allowed_values_function', Vocabulary::class . '::allowedValues')
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => $weight])
      ->setDisplayConfigurable('view', TRUE);
    if ($in_form) {
      $field->setDisplayOptions('form', ['type' => 'options_select', 'weight' => $weight])
        ->setDisplayConfigurable('form', TRUE);
    }
    return $field;
  }

  public static function weight(int $default = 10): BaseFieldDefinition {
    return BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setDefaultValue($default)
      ->setDisplayConfigurable('form', TRUE);
  }

}
