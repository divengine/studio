<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Records are only added to, or moved into, a Development one can edit.
 */
#[Constraint(
  id: 'ProcessDevelopmentEditable',
  label: new TranslatableMarkup('Development can be edited', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessDevelopmentEditableConstraint extends SymfonyConstraint {

  public string $message = 'You cannot add records to %development.';

  public function validatedBy(): string {
    return ProcessDevelopmentEditableConstraintValidator::class;
  }

}
