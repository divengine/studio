<?php

declare(strict_types=1);

namespace Drupal\divengine_process;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
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

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function developmentStates(): array {
    return [
      'planned' => new TranslatableMarkup('Planned'),
      'active' => new TranslatableMarkup('Active'),
      'on_hold' => new TranslatableMarkup('On Hold'),
      'closed' => new TranslatableMarkup('Closed'),
      'cancelled' => new TranslatableMarkup('Cancelled'),
    ];
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function phaseStates(): array {
    return [
      'planned' => new TranslatableMarkup('Planned'),
      'active' => new TranslatableMarkup('Active'),
      'done' => new TranslatableMarkup('Done'),
    ];
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function iterationStates(): array {
    return [
      'planned' => new TranslatableMarkup('Planned'),
      'active' => new TranslatableMarkup('Active'),
      'done' => new TranslatableMarkup('Done'),
      'cancelled' => new TranslatableMarkup('Cancelled'),
    ];
  }

  /**
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function milestoneStates(): array {
    return [
      'planned' => new TranslatableMarkup('Planned'),
      'reached' => new TranslatableMarkup('Reached'),
    ];
  }

  /**
   * Allowed values function for the list fields of process entities.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function allowedValues(FieldStorageDefinitionInterface $definition, ?FieldableEntityInterface $entity = NULL, ?bool &$cacheable = TRUE): array {
    return match ($definition->getTargetEntityTypeId() . '.' . $definition->getName()) {
      'development.state' => static::developmentStates(),
      'development_phase.state' => static::phaseStates(),
      'iteration.state' => static::iterationStates(),
      'milestone.state' => static::milestoneStates(),
      'milestone.milestone_type' => static::milestoneTypes(),
      default => [],
    };
  }

  /**
   * Artifact types of the business and system model.
   *
   * Artifacts of these types belong to exactly one Development.
   *
   * @return array<string, string>
   *   Artifact type ID => the Development page section that lists it.
   */
  public static function scopedArtifactTypes(): array {
    return [
      'business_use_case' => 'business',
      'business_rule' => 'business',
      'business_actor' => 'business',
      'business_worker' => 'business',
      'requirement' => 'requirements',
      'use_case' => 'requirements',
      'system_actor' => 'requirements',
    ];
  }

}
