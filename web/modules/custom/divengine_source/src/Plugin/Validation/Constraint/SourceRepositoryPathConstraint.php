<?php

declare(strict_types=1);

namespace Drupal\divengine_source\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The path of a repository lies inside the allowed roots and stays put once
 * the repository has history.
 */
#[Constraint(
  id: 'SourceRepositoryPath',
  label: new TranslatableMarkup('Repository path', [], ['context' => 'Validation']),
  type: 'entity',
)]
class SourceRepositoryPathConstraint extends SymfonyConstraint {

  public string $notAllowed = 'Repository path %path is not allowed: @reason Allowed roots: @roots.';

  public string $frozen = 'This repository already has indexed history, so its path cannot change: the indexed commits belong to the clone at %path. Register a new repository for another clone.';

  public function validatedBy(): string {
    return SourceRepositoryPathConstraintValidator::class;
  }

}
