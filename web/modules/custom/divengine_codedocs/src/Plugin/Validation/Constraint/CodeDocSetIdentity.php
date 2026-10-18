<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The identifier of a documentation set is well formed and, with history,
 * fixed.
 */
#[Constraint(
  id: 'CodeDocSetIdentity',
  label: new TranslatableMarkup('Identity of a documentation set', [], ['context' => 'Validation']),
  type: ['entity:codedoc_set'],
)]
class CodeDocSetIdentity extends SymfonyConstraint {

  public string $format = 'Use letters, digits, dot, dash and underscore in the identifier.';

  public string $fixed = 'The identifier of a documentation set cannot change once it has snapshots: they were published under it. Register another set instead.';

  public string $developmentFixed = 'The Development cannot change once documentation snapshots exist. Register another set instead.';

}
