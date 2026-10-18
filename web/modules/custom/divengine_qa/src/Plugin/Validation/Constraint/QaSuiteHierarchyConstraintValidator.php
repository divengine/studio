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
 * Validates the QaSuiteHierarchy constraint.
 */
class QaSuiteHierarchyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_qa\Plugin\Validation\Constraint\QaSuiteHierarchyConstraint $constraint */
    $parent = $entity->get('parent')->entity;
    $plan = (string) $entity->get('plan')->target_id;
    if ($parent) {
      if (!$entity->isNew() && (string) $parent->id() === (string) $entity->id()) {
        $this->context->buildViolation($constraint->self)->atPath('parent')->addViolation();
        return;
      }
      if ((string) $parent->get('plan')->target_id !== $plan) {
        $this->context->buildViolation($constraint->plan)->atPath('parent')->addViolation();
      }
      // Walking up from the parent must never meet this suite.
      $seen = [];
      $current = $parent;
      while ($current && !$entity->isNew()) {
        if ((string) $current->id() === (string) $entity->id() || isset($seen[$current->id()])) {
          $this->context->buildViolation($constraint->cycle)->atPath('parent')->addViolation();
          break;
        }
        $seen[$current->id()] = TRUE;
        $current = $current->get('parent')->entity;
      }
    }
    if (!$entity->isNew()) {
      $children = $this->entityTypeManager->getStorage('qa_test_suite')->getQuery()
        ->accessCheck(FALSE)
        ->condition('parent', $entity->id())
        ->condition('plan', $plan, '<>')
        ->count()
        ->execute();
      if ($children) {
        $this->context->buildViolation($constraint->children)->atPath('plan')->addViolation();
      }
    }
  }

}
