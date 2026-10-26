<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A valid link, a permanent identity, one reference per work and Development.
 */
#[Constraint(
  id: 'ExternalReferenceIdentity',
  label: new TranslatableMarkup('External reference identity', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ExternalReferenceIdentityConstraint extends SymfonyConstraint {

  public string $changed = 'The link and Development of an external reference cannot change; register a new reference instead.';

  public string $duplicate = 'This work is already referenced in the Development as %label.';

  public function validatedBy(): string {
    return ExternalReferenceIdentityConstraintValidator::class;
  }

}
