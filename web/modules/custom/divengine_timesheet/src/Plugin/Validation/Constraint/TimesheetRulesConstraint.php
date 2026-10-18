<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A timesheet entry has real hours and its task fits its project.
 */
#[Constraint(
  id: 'TimesheetRules',
  label: new TranslatableMarkup('Timesheet entry rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class TimesheetRulesConstraint extends SymfonyConstraint {

  public string $hours = 'Enter the time spent: more than 0 and at most 24 hours.';

  public string $task = 'The task %task is not part of the project %project.';

  public function validatedBy(): string {
    return TimesheetRulesConstraintValidator::class;
  }

}
