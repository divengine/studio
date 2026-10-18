<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * No change request stays accepted without being scheduled.
 */
#[ReadinessCriterion(
  id: 'change_requests_scheduled',
  label: new TranslatableMarkup('Accepted change requests are scheduled'),
)]
class ChangeRequestsScheduled extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $reasons = [];
    foreach ($this->process->children($development, 'change_request') as $change) {
      if ($change->get('state')->value === 'accepted' && $change->get('status')->value) {
        $reasons[] = (string) $this->t('@record is accepted but not scheduled.', ['@record' => $change->label()]);
      }
    }
    return ReadinessResult::fromReasons($reasons);
  }

}
