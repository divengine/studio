<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Every requirement in ['approved', 'published'] has passing tests verifying it.
 */
#[ReadinessCriterion(
  id: 'requirements_verified',
  label: new TranslatableMarkup('Approved requirements are verified by passing tests'),
)]
class RequirementsVerified extends QaVerificationCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $records = array_filter($this->current($development, 'requirement'), fn ($artifact) => in_array($this->state($artifact), ['approved', 'published'], TRUE));
    return $this->verified($records);
  }

}
