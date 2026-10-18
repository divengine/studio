<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProcessUniqueInDevelopment constraint.
 */
class ProcessUniqueInDevelopmentConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    /** @var \Drupal\divengine_process\Plugin\Validation\Constraint\ProcessUniqueInDevelopmentConstraint $constraint */
    $field = $entity instanceof FieldableEntityInterface ? ($constraint::FIELDS[$entity->bundle()] ?? NULL) : NULL;
    if (!$field || !$entity->hasField($field) || $entity->get('development')->isEmpty()) {
      return;
    }
    $value = trim((string) $entity->get($field)->value);
    if ($value === '') {
      return;
    }
    $query = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $entity->bundle())
      ->condition('development', $entity->get('development')->target_id)
      ->condition($field, $value);
    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }
    if ($query->count()->execute()) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%value', $value)
        ->setParameter('%type', mb_strtolower((string) $entity->get('type')->entity?->label()))
        ->atPath($field)
        ->addViolation();
    }
  }

}
