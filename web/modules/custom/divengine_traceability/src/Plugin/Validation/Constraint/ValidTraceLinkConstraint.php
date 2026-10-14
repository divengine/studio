<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a trace link: existing ends, allowed by its type, not duplicated.
 *
 * Messages come from TraceLinkValidator, one violation per problem.
 */
#[Constraint(
  id: 'ValidTraceLink',
  label: new TranslatableMarkup('Valid trace link', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ValidTraceLinkConstraint extends SymfonyConstraint {

  public function validatedBy(): string {
    return ValidTraceLinkConstraintValidator::class;
  }

}
