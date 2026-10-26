<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Plugin\Validation\Constraint;

use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_external\InvalidExternalUrlException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the identity of external references.
 *
 * A new reference whose identity was not set from the link gets it here, so
 * that forms report an unsupported link on the link field.
 */
class ExternalReferenceIdentityConstraintValidator extends ConstraintValidator {

  public function validate(mixed $reference, Constraint $constraint): void {
    if (!$reference instanceof ExternalReference) {
      return;
    }
    $references = \Drupal::service('divengine_external.references');
    if ($reference->isNew()) {
      try {
        $identity = $references->identify((string) $reference->get('url')->value);
      }
      catch (InvalidExternalUrlException $e) {
        $this->context->buildViolation($e->getMessage())->atPath('url')->addViolation();
        return;
      }
      if ($reference->get('provider')->isEmpty()) {
        $reference->setIdentity($identity);
      }
      $development = $reference->get('development')->target_id;
      $existing = $development ? $references->find($development, $reference->identityValues()) : NULL;
      if ($existing) {
        // Only someone who may see the existing reference learns its label;
        // the Development constraint refuses everybody else anyway.
        $this->context->buildViolation($constraint->duplicate)
          ->setParameter('%label', $existing->access('view') ? $existing->label() : '…')
          ->atPath('url')
          ->addViolation();
      }
      return;
    }
    $original = \Drupal::entityTypeManager()->getStorage('external_reference')->loadUnchanged($reference->id());
    if ($original instanceof ExternalReference && (
      $original->identityValues() !== $reference->identityValues()
      || $original->getUrl() !== $reference->getUrl()
      || (string) $original->get('development')->target_id !== (string) $reference->get('development')->target_id
    )) {
      $this->context->buildViolation($constraint->changed)->atPath('url')->addViolation();
    }
  }

}
