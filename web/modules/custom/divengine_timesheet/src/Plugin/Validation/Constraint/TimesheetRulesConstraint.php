<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A timesheet entry has real hours, a project and a task of that project.
 */
#[Constraint(
  id: 'TimesheetRules',
  label: new TranslatableMarkup('Timesheet entry rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class TimesheetRulesConstraint extends SymfonyConstraint {

  public string $hours = 'Enter the time spent: more than 0 and at most 24 hours.';

  public string $project = 'Choose a project or a task.';

  public string $task = 'The task %task is part of %task_project, not of %project. Choose %task_project or leave the project empty.';

  public function validatedBy(): string {
    return TimesheetRulesConstraintValidator::class;
  }

}
