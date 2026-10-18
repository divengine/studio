<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\divengine_process\Vocabulary;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessDevelopmentRequired constraint.
 */
class ProcessDevelopmentRequiredConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface || !isset(Vocabulary::scopedArtifactTypes()[$entity->bundle()])) {
      return;
    }
    if ($entity->get('development')->isEmpty()) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%type', (string) $entity->get('type')->entity?->label())
        ->atPath('development')
        ->addViolation();
    }
  }

}
