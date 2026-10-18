<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Suite hierarchy within one plan, without cycles.
 */
#[Constraint(
  id: 'QaSuiteHierarchy',
  label: new TranslatableMarkup('Suite hierarchy within one plan, without cycles', [], ['context' => 'Validation']),
  type: 'entity',
)]
class QaSuiteHierarchyConstraint extends SymfonyConstraint {

  public string $self = 'A Suite cannot be its own parent.';

  public string $plan = 'A child Suite must belong to the same Test Plan as its parent.';

  public string $children = 'A parent Suite and all its child Suites must belong to the same Test Plan.';

  public string $cycle = 'A suite hierarchy cycle is not allowed.';

  public function validatedBy(): string {
    return QaSuiteHierarchyConstraintValidator::class;
  }

}
