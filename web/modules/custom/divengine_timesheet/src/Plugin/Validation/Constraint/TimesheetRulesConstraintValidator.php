<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Plugin\Validation\Constraint;

use Drupal\divengine_timesheet\Entity\Timesheet;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the TimesheetRules constraint.
 *
 * Entries are positive and at most 24 hours; negative entries are only
 * written by the weekly grid to adjust a day, so they are allowed down to
 * -24 hours.
 */
class TimesheetRulesConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entry, Constraint $constraint): void {
    if (!$entry instanceof Timesheet) {
      return;
    }
    /** @var \Drupal\divengine_timesheet\Plugin\Validation\Constraint\TimesheetRulesConstraint $constraint */
    $hours = $entry->getHours();
    $negative_allowed = (bool) $entry->get('adjustment')->value;
    if ($hours == 0 || abs($hours) > 24 || ($hours < 0 && !$negative_allowed)) {
      $this->context->buildViolation($constraint->hours)->atPath('hours')->addViolation();
    }
    $task = $entry->get('task')->entity;
    $project = $entry->get('project')->entity;
    if ($task && $project && (string) $task->get('project')->target_id !== (string) $project->id()) {
      $this->context->buildViolation($constraint->task)
        ->setParameter('%task', $task->label())
        ->setParameter('%project', $project->label())
        ->atPath('task')
        ->addViolation();
    }
  }

}
