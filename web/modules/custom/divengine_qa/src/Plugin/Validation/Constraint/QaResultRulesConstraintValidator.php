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
 * Validates the QaResultRules constraint.
 */
class QaResultRulesConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function validate(mixed $record, Constraint $constraint): void {
    if (!$record instanceof FieldableEntityInterface || $record->isTrusted()) {
      return;
    }
    /** @var \Drupal\divengine_qa\Plugin\Validation\Constraint\QaResultRulesConstraint $constraint */
    if ($record->isNew()) {
      $this->context->buildViolation($constraint->created)->addViolation();
      return;
    }
    $original = $this->entityTypeManager->getStorage($record->getEntityTypeId())->loadUnchanged($record->id());
    $run = $original->get('run')->entity;
    $changed = fn (array $fields) => array_values(array_filter($fields, fn ($field) => $original->get($field)->getValue() != $record->get($field)->getValue()));
    if ($run && $run->isHistorical() && $changed(array_keys($record->getFieldDefinitions()))) {
      $this->context->buildViolation($constraint->historical)->addViolation();
      return;
    }
    $labels = fn (array $fields) => implode(', ', array_map(fn ($field) => (string) $record->getFieldDefinition($field)->getLabel(), $fields));
    if ($frozen = $changed($record::snapshotFields())) {
      $this->context->buildViolation($constraint->frozen)->setParameter('@labels', $labels($frozen))->addViolation();
    }
    if ($system = $changed($record::systemFields())) {
      $this->context->buildViolation($constraint->system)->setParameter('@labels', $labels($system))->addViolation();
    }
    if ($changed($record::runtimeFields())) {
      $this->context->buildViolation($constraint->runtime)->addViolation();
    }
  }

}
