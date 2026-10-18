<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Unified Process rules of trace links.
 *
 * Records of a Development are only traced to records of the same
 * Development (or to records outside any Development); only a change
 * request impacts Unified Process records; includes and specializes never
 * form a cycle.
 */
#[Constraint(
  id: 'ProcessTrace',
  label: new TranslatableMarkup('Unified Process trace rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessTraceConstraint extends SymfonyConstraint {

  public string $crossDevelopment = 'Traces between records of a Development stay inside that Development.';

  public string $impacts = 'Only a change request impacts Unified Process records.';

  public string $cycle = 'This %relation link would create a cycle between use cases.';

  public function validatedBy(): string {
    return ProcessTraceConstraintValidator::class;
  }

}
