<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A QA Issue and all its executions belong to the same Development.
 */
#[Constraint(
  id: 'QaIssueDevelopment',
  label: new TranslatableMarkup('A QA Issue and its executions share a Development', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaIssueDevelopmentConstraint extends SymfonyConstraint {

  public string $message = "The QA Issue '@issue' references QA executions of another Development (@names). A QA Issue and all its linked executions must belong to the same Development; report the issue again in the target Development instead.";

  public function validatedBy(): string {
    return QaIssueDevelopmentConstraintValidator::class;
  }

}
