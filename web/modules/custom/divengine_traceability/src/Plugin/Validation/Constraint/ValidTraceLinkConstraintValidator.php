<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\divengine_traceability\TraceLinkValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ValidTraceLinkConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected TraceLinkValidator $traceLinkValidator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('divengine_traceability.trace_link_validator'),
    );
  }

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof EntityInterface) {
      return;
    }

    $source_type = $entity->get('source_entity_type')->value ?? '';
    $source_id = (int) ($entity->get('source_entity_id')->value ?? 0);
    $target_type = $entity->get('target_entity_type')->value ?? '';
    $target_id = (int) ($entity->get('target_entity_id')->value ?? 0);

    $errors = $this->traceLinkValidator->validateAll($source_type, $source_id, $target_type, $target_id);

    if ($errors) {
      $this->context->addViolation(implode(' ', $errors));
    }
  }

}
