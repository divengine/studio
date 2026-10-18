<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The state of a lifecycle only changes through its transitions.
 */
#[Constraint(
  id: 'ProcessStateTransition',
  label: new TranslatableMarkup('Lifecycle state changes through transitions', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessStateTransitionConstraint extends SymfonyConstraint {

  public string $message = '%label can only change state through its transitions.';

  public function validatedBy(): string {
    return ProcessStateTransitionConstraintValidator::class;
  }

}
