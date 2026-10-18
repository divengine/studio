<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessUniqueFields constraint.
 */
class ProcessUniqueFieldsConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessUniqueFieldsConstraint $constraint */
    $query = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->getQuery()->accessCheck(FALSE);
    foreach ($constraint->fields as $field_name) {
      $item = $entity->get($field_name)->first();
      $value = $item?->getValue()[$item->mainPropertyName()] ?? NULL;
      if ($value === NULL || $value === '') {
        return;
      }
      $query->condition($field_name, is_string($value) ? trim($value) : $value);
    }
    if (!$entity->isNew()) {
      $query->condition($entity->getEntityType()->getKey('id'), $entity->id(), '<>');
    }
    if ($query->count()->execute()) {
      $this->context->buildViolation($constraint->message)
        ->atPath(end($constraint->fields))
        ->addViolation();
    }
  }

}
