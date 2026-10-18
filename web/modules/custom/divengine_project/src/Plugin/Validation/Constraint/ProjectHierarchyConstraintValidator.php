<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Plugin\Validation\Constraint;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\divengine_project\Entity\ProjectTask;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProjectHierarchy constraint.
 */
class ProjectHierarchyConstraintValidator extends ConstraintValidator {

  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    /** @var \Drupal\divengine_project\Plugin\Validation\Constraint\ProjectHierarchyConstraint $constraint */
    $parent = $entity->get('parent')->entity;
    if ($parent && !$entity->isNew()) {
      // Walk up from the parent: meeting this record means a cycle.
      $seen = [];
      for ($node = $parent; $node; $node = $node->get('parent')->entity) {
        if ((string) $node->id() === (string) $entity->id() || isset($seen[$node->id()])) {
          $this->context->buildViolation($constraint->cycle)
            ->setParameter('%parent', $parent->label())
            ->atPath('parent')
            ->addViolation();
          break;
        }
        $seen[$node->id()] = TRUE;
      }
    }
    if (!$entity instanceof ProjectTask) {
      return;
    }
    $project = $entity->getProject();
    if ($parent && $project && (string) $parent->get('project')->target_id !== (string) $project->id()) {
      $this->context->buildViolation($constraint->otherProject)
        ->setParameter('%parent', $parent->label())
        ->setParameter('%project', (string) $parent->getProject()?->label())
        ->atPath('parent')
        ->addViolation();
    }
    $stage = $entity->getStage();
    if ($stage && $project && !isset($project->getStages()[$stage->id()])) {
      $this->context->buildViolation($constraint->stage)
        ->setParameter('%stage', $stage->label())
        ->setParameter('%project', $project->label())
        ->atPath('stage')
        ->addViolation();
    }
  }

}
