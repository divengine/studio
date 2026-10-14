<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniqueArtifactKeyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof EntityInterface) {
      return;
    }

    // preSave() trims the key, so compare the value that will be stored.
    $key = trim((string) $entity->get('artifact_key')->value);
    if ($key === '') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('artifact_key', $key);

    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }

    if ($query->count()->execute() > 0) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%key', $key)
        ->atPath('artifact_key')
        ->addViolation();
    }
  }

}
