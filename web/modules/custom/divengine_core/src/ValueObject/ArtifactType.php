<?php

declare(strict_types=1);

namespace Drupal\divengine_core\ValueObject;

/**
 * Defines canonical artifact type identifiers for Divengine Studio.
 *
 * These are the initial types supported out of the box.
 * Additional types can be added via the ArtifactType config entity
 * in the divengine_artifact module.
 */
enum ArtifactType: string {

  case Requirement = 'requirement';
  case UseCase = 'use_case';
  case Decision = 'decision';
  case Risk = 'risk';
  case TestCase = 'test_case';
  case Evidence = 'evidence';
  case KnowledgeReference = 'knowledge_reference';
  case MethodFragment = 'method_fragment';
  case StandardReference = 'standard_reference';

  /**
   * Returns a human-readable label for each type.
   */
  public function label(): string {
    return match ($this) {
      self::Requirement => 'Requirement',
      self::UseCase => 'Use Case',
      self::Decision => 'Decision',
      self::Risk => 'Risk',
      self::TestCase => 'Test Case',
      self::Evidence => 'Evidence',
      self::KnowledgeReference => 'Knowledge Reference',
      self::MethodFragment => 'Method Fragment',
      self::StandardReference => 'Standard Reference',
    };
  }

  /**
   * Returns all values as an associative array for select widgets.
   *
   * @return array<string, string>
   */
  public static function options(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }
    return $options;
  }

}
