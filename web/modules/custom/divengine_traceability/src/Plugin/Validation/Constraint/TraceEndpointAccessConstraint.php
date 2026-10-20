<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Whoever creates a trace, or changes its ends, may see both ends.
 *
 * Authorization of the writer, applied when the link is validated (forms,
 * JSON:API). Persistent integrity is enforced by storage separately.
 */
#[Constraint(
  id: 'TraceEndpointAccess',
  label: new TranslatableMarkup('Trace endpoints visible to the writer', [], ['context' => 'Validation']),
  type: 'entity',
)]
class TraceEndpointAccessConstraint extends SymfonyConstraint {

  public string $message = 'You cannot trace to or from a record you are not allowed to see.';

  public function validatedBy(): string {
    return TraceEndpointAccessConstraintValidator::class;
  }

}
