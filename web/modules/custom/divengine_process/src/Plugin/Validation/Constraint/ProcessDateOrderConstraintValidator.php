<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessDateOrder constraint.
 */
class ProcessDateOrderConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessDateOrderConstraint $constraint */
    $start = $entity->get($constraint->start)->value;
    $end = $entity->get($constraint->end)->value;
    // Date-only values are ISO strings, so they compare as text.
    if ($start && $end && $end < $start) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%entity', (string) ($entity->label() ?? $entity->getEntityType()->getSingularLabel()))
        ->atPath($constraint->end)
        ->addViolation();
    }
  }

}
