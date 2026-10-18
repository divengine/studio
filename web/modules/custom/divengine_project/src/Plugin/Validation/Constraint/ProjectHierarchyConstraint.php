<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Parents make a tree; a task's parent and stage fit its project.
 */
#[Constraint(
  id: 'ProjectHierarchy',
  label: new TranslatableMarkup('Projects and tasks form trees', [], ['context' => 'Validation']),
  type: 'entity',
)]
class ProjectHierarchyConstraint extends SymfonyConstraint {

  public string $cycle = '%parent cannot be the parent: it is this record or one of its descendants.';

  public string $otherProject = 'A subtask belongs to the project of its parent task: %parent is in %project.';

  public string $stage = 'The stage %stage is not on the board of %project.';

  public function validatedBy(): string {
    return ProjectHierarchyConstraintValidator::class;
  }

}
