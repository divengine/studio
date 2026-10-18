<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Steps referenced by results keep their case.
 */
#[Constraint(
  id: 'QaStepCase',
  label: new TranslatableMarkup('Steps referenced by results keep their case', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaStepCaseConstraint extends SymfonyConstraint {

  public string $message = 'A Test Step referenced by a Step Result must belong to the Test Case represented by its Test Execution.';

  public function validatedBy(): string {
    return QaStepCaseConstraintValidator::class;
  }

}
