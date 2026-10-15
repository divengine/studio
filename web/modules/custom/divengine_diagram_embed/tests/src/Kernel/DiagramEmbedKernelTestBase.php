<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\divengine_modeler\Kernel\ModelerKernelTestBase;

/**
 * Installs the embed module next to test hosts with a formatted text field.
 */
abstract class DiagramEmbedKernelTestBase extends ModelerKernelTestBase {

  protected static $modules = [
    'field',
    'filter',
    'text',
    'editor',
    'ckeditor5',
    'entity_test',
    'divengine_diagram_embed',
  ];

  /**
   * Host entity types given a "body" formatted text field.
   */
  protected array $hostTypes = ['entity_test', 'entity_test_rev'];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('divengine_diagram_embed', ['diagram_usage']);
    $this->installConfig(['filter']);
    foreach ($this->hostTypes as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
      FieldStorageConfig::create([
        'entity_type' => $entity_type_id,
        'field_name' => 'body',
        'type' => 'text_long',
      ])->save();
      FieldConfig::create([
        'entity_type' => $entity_type_id,
        'bundle' => $entity_type_id,
        'field_name' => 'body',
      ])->save();
    }
  }

  protected function embed(string $uuid, array $attributes = []): string {
    $markup = '<drupal-diagram data-diagram-uuid="' . $uuid . '"';
    foreach ($attributes as $name => $value) {
      $markup .= ' ' . $name . '="' . $value . '"';
    }
    return $markup . '></drupal-diagram>';
  }

}
