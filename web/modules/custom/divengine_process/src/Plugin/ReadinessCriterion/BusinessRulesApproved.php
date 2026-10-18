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
  id: 'business_rules_approved',
  label: new TranslatableMarkup('Business rules are approved'),
)]
class BusinessRulesApproved extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $records = $this->current($development, 'business_rule');
    if (!$records) {
      return ReadinessResult::fromReasons([(string) $this->t('The Development has no business rule.')]);
    }
    return ReadinessResult::fromReasons($this->pending($records, ['draft', 'proposed'], fn (string $record) => $this->t('@record is not approved yet.', ['@record' => $record])));
  }

}
