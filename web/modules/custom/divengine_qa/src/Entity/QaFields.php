<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_qa\QaVocabulary;

/**
 * Base field definitions shared by the QA content entities.
 */
final class QaFields {

  public static function code(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Reference'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A list field whose values come from the QA vocabulary.
   */
  public static function list(TranslatableMarkup $label, string $default, int $weight, bool $in_form = FALSE): BaseFieldDefinition {
    $field = BaseFieldDefinition::create('list_string')
      ->setLabel($label)
      ->setRequired(TRUE)
      ->setDefaultValue($default)
      ->setSetting('allowed_values_function', QaVocabulary::class . '::allowedValues');
    if ($in_form) {
      $field->setDisplayOptions('form', ['type' => 'options_select', 'weight' => $weight])
        ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'list_default', 'weight' => $weight])
        ->setDisplayConfigurable('form', TRUE);
    }
    return $field->setDisplayConfigurable('view', TRUE);
  }

  /**
   * Tags: a multi-value reference to QA tags.
   */
  public static function tags(int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Tags'))
      ->setSetting('target_type', 'qa_tag')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => $weight, 'settings' => ['link' => FALSE]])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * A frozen copy kept by snapshots; never shown in forms.
   */
  public static function frozen(string $type, TranslatableMarkup $label): BaseFieldDefinition {
    return BaseFieldDefinition::create($type)->setLabel($label);
  }

  public static function files(TranslatableMarkup $label, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('file')
      ->setLabel($label)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('file_extensions', 'png jpg jpeg gif webp pdf txt log csv json xml zip mp4 webm har')
      ->setSetting('uri_scheme', 'private')
      ->setSetting('file_directory', 'qa/[date:custom:Y]-[date:custom:m]')
      ->setDisplayOptions('form', ['type' => 'file_generic', 'weight' => $weight])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'file_default', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

}
