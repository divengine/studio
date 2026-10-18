<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Every use_case in ['reviewed', 'published'] has passing tests verifying it.
 */
#[ReadinessCriterion(
  id: 'use_cases_verified',
  label: new TranslatableMarkup('Reviewed use cases are verified by passing tests'),
)]
class UseCasesVerified extends QaVerificationCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $records = array_filter($this->current($development, 'use_case'), fn ($artifact) => in_array($this->state($artifact), ['reviewed', 'published'], TRUE));
    return $this->verified($records);
  }

}
