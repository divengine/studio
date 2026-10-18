<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessDevelopmentEditable constraint.
 */
class ProcessDevelopmentEditableConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField('development')) {
      return;
    }
    $development = $entity->get('development')->entity;
    if (!$development) {
      return;
    }
    if (!$entity->isNew()) {
      $original = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
      if ($original && (string) $original->get('development')->target_id === (string) $development->id()) {
        return;
      }
    }
    if (!$development->access('update')) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%development', (string) $development->label())
        ->atPath('development')
        ->addViolation();
    }
  }

}
