<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stable keys and labels of the Unified Process vocabulary.
 *
 * Keys are stored values and never change; labels are translatable.
 */
final class Vocabulary {

  /**
   * Lifecycle milestones, in lifecycle order.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function milestoneTypes(): array {
    return [
      'lifecycle_objectives' => new TranslatableMarkup('Lifecycle Objectives'),
      'lifecycle_architecture' => new TranslatableMarkup('Lifecycle Architecture'),
      'initial_operational_capability' => new TranslatableMarkup('Initial Operational Capability'),
      'product_release' => new TranslatableMarkup('Product Release'),
    ];
  }

  /**
   * How much a Development Case asks for an element.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function levels(): array {
    return [
      'required' => new TranslatableMarkup('Required'),
      'recommended' => new TranslatableMarkup('Recommended'),
      'optional' => new TranslatableMarkup('Optional'),
      'disabled' => new TranslatableMarkup('Disabled'),
    ];
  }

  /**
   * What a Development Case line tailors.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function caseElementTypes(): array {
    return [
      'discipline' => new TranslatableMarkup('Discipline'),
      'activity_definition' => new TranslatableMarkup('Activity Definition'),
      'work_product_definition' => new TranslatableMarkup('Work Product Definition'),
      'readiness' => new TranslatableMarkup('Readiness Criterion'),
    ];
  }

  /**
   * The config entity type each element type points to; readiness has none.
   *
   * @return array<string, string>
   */
  public static function caseElementEntityTypes(): array {
    return [
      'discipline' => 'process_discipline',
      'activity_definition' => 'activity_definition',
      'work_product_definition' => 'work_product_definition',
    ];
  }

}
