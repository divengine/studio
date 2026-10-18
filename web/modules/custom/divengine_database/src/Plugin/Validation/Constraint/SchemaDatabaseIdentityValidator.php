<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates SchemaDatabaseIdentity.
 */
class SchemaDatabaseIdentityValidator extends ConstraintValidator {

  public function validate($entity, Constraint $constraint): void {
    /** @var \Drupal\divengine_database\Entity\SchemaDatabase $entity */
    $identifier = trim((string) $entity->get('identifier')->value);
    if ($identifier !== '' && !preg_match('/^[A-Za-z0-9_.\-]+$/', $identifier)) {
      $this->context->buildViolation($constraint->format)->atPath('identifier')->addViolation();
    }
    if ($entity->isNew() || !$entity->hasHistory()) {
      return;
    }
    $original = \Drupal::entityTypeManager()->getStorage('schema_database')->loadUnchanged($entity->id());
    if ($original->get('identifier')->value !== $identifier) {
      $this->context->buildViolation($constraint->fixed, ['@field' => 'identifier'])->atPath('identifier')->addViolation();
    }
    $engine = mb_strtolower(trim((string) $entity->get('engine')->value));
    if ((string) $original->get('engine')->value !== '' && $original->get('engine')->value !== $engine) {
      $this->context->buildViolation($constraint->fixed, ['@field' => 'engine'])->atPath('engine')->addViolation();
    }
  }

}
