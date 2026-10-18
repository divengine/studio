<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Names and references that are unique within a Development.
 *
 * Actor and worker names are unique per Development and type; a business
 * rule's source reference is unique per Development when it is given.
 */
#[Constraint(
  id: 'ProcessUniqueInDevelopment',
  label: new TranslatableMarkup('Unique within the Development', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessUniqueInDevelopmentConstraint extends SymfonyConstraint {

  /**
   * Field that must be unique, per artifact type.
   */
  const FIELDS = [
    'system_actor' => 'title',
    'business_actor' => 'title',
    'business_worker' => 'title',
    'business_rule' => 'field_source_ref',
  ];

  public string $message = '%value is already used by another %type of this Development.';

  public function validatedBy(): string {
    return ProcessUniqueInDevelopmentConstraintValidator::class;
  }

}
