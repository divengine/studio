<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Change requests marked Verified have passing test evidence.
 *
 * The Verified state stays a human decision; the criterion only flags it
 * when the evidence is missing.
 */
#[ReadinessCriterion(
  id: 'change_requests_evidenced',
  label: new TranslatableMarkup('Change requests marked verified have passing test evidence'),
)]
class ChangeRequestsEvidenced extends QaVerificationCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $storage = $this->entityTypeManager->getStorage('change_request');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('development', $development->id())->condition('state', 'verified')->execute();
    return $this->verified($storage->loadMultiple($ids));
  }

}
