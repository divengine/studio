<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Tests are traced only as verification of process records.
 */
#[Constraint(
  id: 'QaVerificationTrace',
  label: new TranslatableMarkup('Tests are traced only as verification', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaVerificationTraceConstraint extends SymfonyConstraint {

  public string $relation = 'A test plan, suite, case or run verifies a record, and those are only related as verification: trace them from the test with "Verifies".';

  public string $target = 'Only requirements, use cases, change requests and work products are verified by tests.';

  public function validatedBy(): string {
    return QaVerificationTraceConstraintValidator::class;
  }

}
