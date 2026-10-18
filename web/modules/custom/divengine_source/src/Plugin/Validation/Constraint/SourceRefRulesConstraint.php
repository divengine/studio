<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A ref has a valid, unique name, at most one is the default, and an
 * indexed ref keeps its name.
 */
#[Constraint(
  id: 'SourceRefRules',
  label: new TranslatableMarkup('Ref rules', [], ['context' => 'Validation']),
  type: 'entity',
)]
class SourceRefRulesConstraint extends SymfonyConstraint {

  public string $invalid = '@message';

  public string $duplicate = 'The ref %name is already registered for this repository.';

  public string $default = '%repository already has a default ref.';

  public string $renamed = 'An indexed ref cannot be renamed or moved: register a new ref.';

  public function validatedBy(): string {
    return SourceRefRulesConstraintValidator::class;
  }

}
