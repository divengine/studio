<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'ValidTraceLink',
  label: new TranslatableMarkup('Valid trace link', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ValidTraceLinkConstraint extends SymfonyConstraint {

  public string $sourceMessage = 'Source entity of type "@type" with ID @id does not exist.';
  public string $targetMessage = 'Target entity of type "@type" with ID @id does not exist.';
  public string $distinctMessage = 'Source and target cannot be the same entity.';
  public string $sourceTypeMessage = 'Entity type "@type" does not exist.';
  public string $targetTypeMessage = 'Entity type "@type" does not exist.';

  public function validatedBy(): string {
    return ValidTraceLinkConstraintValidator::class;
  }

}
