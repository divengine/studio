<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Approved requirements are covered by a use case or traced downstream.
 */
#[ReadinessCriterion(
  id: 'requirements_refined',
  label: new TranslatableMarkup('Approved requirements are refined or realized downstream'),
)]
class RequirementsRefined extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    return ReadinessResult::fromReasons(array_map(
      fn ($requirement) => (string) $this->t('@record has no use case and no refining or realizing trace.', ['@record' => trim($requirement->get('artifact_key')->value . ' ' . $requirement->label())]),
      $this->gaps->requirementsNotRefined($development),
    ));
  }

}
