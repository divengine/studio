<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * At least one current record of the type, and none of them still pending.
 */
#[ReadinessCriterion(
  id: 'business_use_cases_reviewed',
  label: new TranslatableMarkup('Business use cases are reviewed'),
)]
class BusinessUseCasesReviewed extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $records = $this->current($development, 'business_use_case');
    if (!$records) {
      return ReadinessResult::fromReasons([(string) $this->t('The Development has no business use case.')]);
    }
    return ReadinessResult::fromReasons($this->pending($records, ['draft', 'outlined', 'detailed'], fn (string $record) => $this->t('@record is not reviewed yet.', ['@record' => $record])));
  }

}
