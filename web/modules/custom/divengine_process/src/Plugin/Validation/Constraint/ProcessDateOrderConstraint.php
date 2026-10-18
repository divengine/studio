<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * An end date is not before its start date.
 */
#[Constraint(
  id: 'ProcessDateOrder',
  label: new TranslatableMarkup('End date not before start date', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessDateOrderConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    public string $start = 'date_start',
    public string $end = 'date_end',
    public string $message = 'The end date of %entity is before its start date.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  public function validatedBy(): string {
    return ProcessDateOrderConstraintValidator::class;
  }

}
