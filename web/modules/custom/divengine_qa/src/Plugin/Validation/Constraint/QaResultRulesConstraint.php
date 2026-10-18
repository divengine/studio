<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Executions and step results only change through the result workflow.
 */
#[Constraint(
  id: 'QaResultRules',
  label: new TranslatableMarkup('Test result rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaResultRulesConstraint extends SymfonyConstraint {

  public string $created = 'Test Executions are generated from the Test Plan snapshot when a Test Run is created; they cannot be created manually or imported. Use Add Scenario on an In Progress Run to record an unplanned scenario.';

  public string $historical = 'Executions of a Completed or Cancelled Run are historical evidence and cannot be modified. Reopen a Completed Run before making corrections.';

  public string $frozen = 'Historical snapshot fields are frozen when the Test Run is created and cannot be modified: @labels.';

  public string $system = '@labels are maintained by the result workflow and cannot be written directly.';

  public string $runtime = 'Record results, observations and reasons with the result actions of the execution.';

  public function validatedBy(): string {
    return QaResultRulesConstraintValidator::class;
  }

}
