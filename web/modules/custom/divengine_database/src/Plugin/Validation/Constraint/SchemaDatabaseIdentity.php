<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The identifier of a database is well formed and, with history, fixed.
 */
#[Constraint(
  id: 'SchemaDatabaseIdentity',
  label: new TranslatableMarkup('Identity of a database', [], ['context' => 'Validation']),
  type: ['entity:schema_database'],
)]
class SchemaDatabaseIdentity extends SymfonyConstraint {

  public string $format = 'Use letters, digits, dot, dash and underscore in the identifier.';

  public string $fixed = 'The @field of a database cannot change once it has snapshots: they were captured under it. Register another database instead.';

}
