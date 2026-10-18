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
 * Validates the QaStepCase constraint.
 */
class QaStepCaseConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof FieldableEntityInterface || $entity->isNew() || !$this->entityTypeManager->hasDefinition('qa_step_result')) {
      return;
    }
    /** @var \Drupal\divengine_qa\Plugin\Validation\Constraint\QaStepCaseConstraint $constraint */
    $results = $this->entityTypeManager->getStorage('qa_step_result');
    foreach ($results->loadByProperties(['test_step' => $entity->id()]) as $result) {
      $execution = $result->get('execution')->entity;
      if ($execution && (string) $execution->get('test_case')->target_id !== (string) $entity->get('case')->target_id) {
        $this->context->buildViolation($constraint->message)->atPath('case')->addViolation();
        return;
      }
    }
  }

}
