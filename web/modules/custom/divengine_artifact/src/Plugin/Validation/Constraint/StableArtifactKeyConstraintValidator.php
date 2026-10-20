<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_artifact\Entity\Artifact;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class StableArtifactKeyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof Artifact || $entity->isNew()) {
      return;
    }
    $assigned = $entity->assignedKey($this->entityTypeManager->getStorage('artifact'));
    if ($assigned !== '' && $assigned !== trim($entity->getArtifactKey())) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%key', $assigned)
        ->atPath('artifact_key')
        ->addViolation();
    }
  }

}
