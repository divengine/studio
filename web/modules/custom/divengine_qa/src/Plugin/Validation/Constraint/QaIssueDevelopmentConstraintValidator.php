<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\divengine_qa\Entity\QaIssue;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the QaIssueDevelopment constraint.
 */
class QaIssueDevelopmentConstraintValidator extends ConstraintValidator {

  public function validate(mixed $issue, Constraint $constraint): void {
    if (!$issue instanceof QaIssue) {
      return;
    }
    $development = (string) $issue->get('development')->target_id;
    $executions = $issue->getExecutions();
    if ($origin = $issue->get('origin')->entity) {
      $executions[] = $origin;
    }
    $foreign = array_filter($executions, fn ($execution) => (string) $execution->get('development')->target_id !== $development);
    if ($foreign) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@issue', $issue->label())
        ->setParameter('@names', implode(', ', array_unique(array_map(fn ($e) => $e->label(), $foreign))))
        ->atPath('executions')
        ->addViolation();
    }
  }

}
