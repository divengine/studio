<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Business and system model artifacts belong to a Development.
 */
#[Constraint(
  id: 'ProcessDevelopmentRequired',
  label: new TranslatableMarkup('Belongs to a Development', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessDevelopmentRequiredConstraint extends SymfonyConstraint {

  public string $message = 'Choose the Development this %type belongs to.';

  public function validatedBy(): string {
    return ProcessDevelopmentRequiredConstraintValidator::class;
  }

}
