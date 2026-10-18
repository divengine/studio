<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the QaPromotedCase constraint.
 */
class QaPromotedCaseConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_qa\Plugin\Validation\Constraint\QaPromotedCaseConstraint $constraint */
    if (!$entity->isNew()) {
      $original = $this->entityTypeManager->getStorage('qa_test_case')->loadUnchanged($entity->id());
      if ($original && (string) $original->get('source_execution')->target_id !== (string) $entity->get('source_execution')->target_id) {
        $this->context->buildViolation($constraint->frozen)->atPath('source_execution')->addViolation();
      }
    }
    $source = $entity->get('source_execution')->entity;
    if (!$source) {
      return;
    }
    if ($source->get('origin')->value !== 'ad_hoc') {
      $this->context->buildViolation($constraint->adHoc)->atPath('source_execution')->addViolation();
    }
    $suite = $entity->get('suite')->entity;
    if ($suite && (string) $suite->get('plan')->target_id !== (string) $source->get('plan')->target_id) {
      $this->context->buildViolation($constraint->plan)->atPath('suite')->addViolation();
    }
  }

}
