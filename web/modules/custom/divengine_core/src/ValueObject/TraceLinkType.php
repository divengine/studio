<?php

declare(strict_types=1);

namespace Drupal\divengine_core\ValueObject;

/**
 * Defines canonical trace link type identifiers for Divengine Studio.
 *
 * Semantic types for connections between engineering artifacts and knowledge.
 */
enum TraceLinkType: string {

  case DerivesFrom = 'derives_from';
  case Satisfies = 'satisfies';
  case Realizes = 'realizes';
  case Validates = 'validates';
  case Tests = 'tests';
  case DependsOn = 'depends_on';
  case ConflictsWith = 'conflicts_with';
  case Mitigates = 'mitigates';
  case Impacts = 'impacts';
  case Documents = 'documents';
  case Supersedes = 'supersedes';
  case References = 'references';

  /**
   * Returns a human-readable label for each type.
   */
  public function label(): string {
    return match ($this) {
      self::DerivesFrom => 'Derives from',
      self::Satisfies => 'Satisfies',
      self::Realizes => 'Realizes',
      self::Validates => 'Validates',
      self::Tests => 'Tests',
      self::DependsOn => 'Depends on',
      self::ConflictsWith => 'Conflicts with',
      self::Mitigates => 'Mitigates',
      self::Impacts => 'Impacts',
      self::Documents => 'Documents',
      self::Supersedes => 'Supersedes',
      self::References => 'References',
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
