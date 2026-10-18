<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A combination of field values is unique among entities of the type.
 *
 * Empty values never collide.
 */
#[Constraint(
  id: 'ProcessUniqueFields',
  label: new TranslatableMarkup('Unique combination of fields', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProcessUniqueFieldsConstraint extends SymfonyConstraint {

  /**
   * @param string[] $fields
   *   The fields whose values together must be unique.
   * @param string $message
   *   The violation message.
   */
  #[HasNamedArguments]
  public function __construct(
    public array $fields = [],
    public string $message = 'This combination already exists.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

  public function validatedBy(): string {
    return ProcessUniqueFieldsConstraintValidator::class;
  }

}
