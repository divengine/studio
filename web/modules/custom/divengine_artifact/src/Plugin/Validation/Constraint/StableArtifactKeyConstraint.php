<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The code of an existing artifact cannot change.
 *
 * Storage enforces the same rule for writers that skip validation.
 */
#[Constraint(
  id: 'StableArtifactKey',
  label: new TranslatableMarkup('Stable artifact key', [], ['context' => 'Validation']),
  type: 'entity',
)]
class StableArtifactKeyConstraint extends SymfonyConstraint {

  public string $message = 'The code %key is permanent and cannot be changed.';

  public function validatedBy(): string {
    return StableArtifactKeyConstraintValidator::class;
  }

}
