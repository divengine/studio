<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rules of test runs edited outside the QA workflow services.
 */
#[Constraint(
  id: 'QaRunRules',
  label: new TranslatableMarkup('Test run rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaRunRulesConstraint extends SymfonyConstraint {

  public string $historical = 'A Completed or Cancelled Run is historical evidence and cannot be modified. Reopen a Completed Run before making corrections.';

  public string $flags = 'Self-created and Automatically Completed are set by the system only.';

  public string $plan = 'The Test Plan of a Run cannot change once its executions were generated from that Plan.';

  public string $url = "The Test URL must be a complete http:// or https:// address, for example https://test.example.com (got '@value').";

  public string $assign = 'Only a QA Lead or Manager can assign a Test Run.';

  public string $reassign = 'Reassigning a Test Run requires a reason. Use Reassign on the Run.';

  public function validatedBy(): string {
    return QaRunRulesConstraintValidator::class;
  }

}
