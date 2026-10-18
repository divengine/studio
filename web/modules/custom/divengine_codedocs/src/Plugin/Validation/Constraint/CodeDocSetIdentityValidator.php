<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates CodeDocSetIdentity.
 */
class CodeDocSetIdentityValidator extends ConstraintValidator {

  public function validate($entity, Constraint $constraint): void {
    /** @var \Drupal\divengine_codedocs\Entity\CodeDocSet $entity */
    $identifier = trim((string) $entity->get('identifier')->value);
    if ($identifier !== '' && !preg_match('/^[A-Za-z0-9_.\-]+$/', $identifier)) {
      $this->context->buildViolation($constraint->format)->atPath('identifier')->addViolation();
    }
    if (!$entity->isNew() && $entity->hasHistory()) {
      $original = \Drupal::entityTypeManager()->getStorage('codedoc_set')->loadUnchanged($entity->id());
      if ($original->get('identifier')->value !== $identifier) {
        $this->context->buildViolation($constraint->fixed)->atPath('identifier')->addViolation();
      }
    }
  }

}
