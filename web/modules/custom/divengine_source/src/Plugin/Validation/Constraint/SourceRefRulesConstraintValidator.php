<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Plugin\Validation\Constraint;

use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\Git\GitException;
use Drupal\divengine_source\Git\GitRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SourceRefRules constraint.
 */
class SourceRefRulesConstraintValidator extends ConstraintValidator {

  public function validate(mixed $ref, Constraint $constraint): void {
    if (!$ref instanceof SourceRef) {
      return;
    }
    /** @var \Drupal\divengine_source\Plugin\Validation\Constraint\SourceRefRulesConstraint $constraint */
    $name = trim((string) $ref->get('name')->value);
    try {
      GitRepository::validateRef($name);
    }
    catch (GitException $e) {
      $this->context->buildViolation($constraint->invalid)->setParameter('@message', $e->getMessage())->atPath('name')->addViolation();
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('source_ref');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('repository', $ref->get('repository')->target_id)->condition('name', $name);
    if (!$ref->isNew()) {
      $query->condition('id', $ref->id(), '<>');
    }
    if ($query->count()->execute()) {
      $this->context->buildViolation($constraint->duplicate)->setParameter('%name', $name)->atPath('name')->addViolation();
    }
    if ($ref->get('is_default')->value) {
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('repository', $ref->get('repository')->target_id)->condition('is_default', 1);
      if (!$ref->isNew()) {
        $query->condition('id', $ref->id(), '<>');
      }
      if ($query->count()->execute()) {
        $this->context->buildViolation($constraint->default)->setParameter('%repository', (string) $ref->getRepository()?->label())->atPath('is_default')->addViolation();
      }
    }
    if (!$ref->isNew() && ($original = $storage->loadUnchanged($ref->id()))
      && ((string) $original->get('name')->value !== $name || (string) $original->get('repository')->target_id !== (string) $ref->get('repository')->target_id)
      && $ref->get('last_snapshot')->target_id) {
      $this->context->buildViolation($constraint->renamed)->atPath('name')->addViolation();
    }
  }

}
