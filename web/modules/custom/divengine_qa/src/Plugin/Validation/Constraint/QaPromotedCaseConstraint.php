<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Promoted cases stay with their scenario.
 */
#[Constraint(
  id: 'QaPromotedCase',
  label: new TranslatableMarkup('Promoted cases stay with their scenario', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaPromotedCaseConstraint extends SymfonyConstraint {

  public string $adHoc = 'Only an ad-hoc scenario can be promoted to a Test Case.';

  public string $plan = 'A promoted Test Case must belong to the Test Plan of the Run where the scenario was discovered.';

  public string $frozen = 'The scenario a Test Case was promoted from is recorded at promotion and cannot be changed.';

  public function validatedBy(): string {
    return QaPromotedCaseConstraintValidator::class;
  }

}
