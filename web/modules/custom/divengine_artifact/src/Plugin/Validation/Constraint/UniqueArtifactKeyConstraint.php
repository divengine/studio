<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'UniqueArtifactKey',
  label: new TranslatableMarkup('Unique artifact key', [], ['context' => 'Validation']),
  type: 'entity',
)]
class UniqueArtifactKeyConstraint extends SymfonyConstraint {

  public string $message = 'The artifact key %key is already in use.';

  public function validatedBy(): string {
    return UniqueArtifactKeyConstraintValidator::class;
  }

}
