<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessSameDevelopment constraint.
 */
class ProcessSameDevelopmentConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessSameDevelopmentConstraint $constraint */
    $development_id = $constraint->self ? $entity->id() : $entity->get('development')->target_id;
    if ($development_id === NULL || $development_id === '') {
      return;
    }
    foreach ($constraint->fields as $field_name) {
      foreach ($entity->get($field_name)->referencedEntities() as $referenced) {
        if (!$referenced instanceof FieldableEntityInterface || !$referenced->hasField('development')) {
          continue;
        }
        if ((string) $referenced->get('development')->target_id !== (string) $development_id) {
          $this->context->buildViolation($constraint->message)
            ->setParameter('%field', (string) $entity->getFieldDefinition($field_name)->getLabel())
            ->setParameter('%entity', (string) ($entity->label() ?? $entity->getEntityType()->getSingularLabel()))
            ->atPath($field_name)
            ->addViolation();
          break;
        }
      }
    }
  }

}
