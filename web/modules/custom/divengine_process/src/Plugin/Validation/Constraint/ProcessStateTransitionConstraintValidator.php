<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\divengine_process\Lifecycle\StatefulInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessStateTransition constraint.
 */
class ProcessStateTransitionConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof StatefulInterface || $entity->isNew() || $entity->isTransitioning()) {
      return;
    }
    $original = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($original instanceof StatefulInterface && $original->getState() !== $entity->getState()) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%label', (string) $entity->label())
        ->atPath('state')
        ->addViolation();
    }
  }

}
