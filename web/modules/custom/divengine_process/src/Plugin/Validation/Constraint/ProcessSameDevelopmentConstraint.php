<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * References stay inside the entity's Development.
 *
 * Each listed entity reference field (every one when none is listed) may
 * only point to records of the same Development as the entity. With "self", the entity is the Development and
 * the referenced records must belong to it.
 */
#[Constraint(
  id: 'ProcessSameDevelopment',
  label: new TranslatableMarkup('References stay inside one Development', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessSameDevelopmentConstraint extends SymfonyConstraint {

  public string $message = '%field of %entity belongs to another Development.';

  /**
   * @param string[] $fields
   *   Entity reference fields to check.
   * @param bool $self
   *   Whether the validated entity is the Development itself.
   */
  #[HasNamedArguments]
  public function __construct(
    public array $fields = [],
    public bool $self = FALSE,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  public function validatedBy(): string {
    return ProcessSameDevelopmentConstraintValidator::class;
  }

}
