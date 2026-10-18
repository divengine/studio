<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Accepted and scheduled change requests have an impacts trace.
 */
#[ReadinessCriterion(
  id: 'change_requests_analyzed',
  label: new TranslatableMarkup('Accepted change requests have an impact analysis'),
)]
class ChangeRequestsAnalyzed extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    return ReadinessResult::fromReasons(array_map(
      fn ($change) => (string) $this->t('@record has no impacted record.', ['@record' => $change->label()]),
      $this->gaps->changeRequestsWithoutImpact($development),
    ));
  }

}
