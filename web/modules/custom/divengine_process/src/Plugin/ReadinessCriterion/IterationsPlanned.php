<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * The current phase has at least one iteration that is not cancelled.
 */
#[ReadinessCriterion(
  id: 'iterations_planned',
  label: new TranslatableMarkup('The current phase has planned iterations'),
)]
class IterationsPlanned extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $phase = $development->get('current_phase')->entity;
    if (!$phase) {
      return ReadinessResult::fromReasons([(string) $this->t('No current phase is set.')]);
    }
    $count = $this->entityTypeManager->getStorage('iteration')->getQuery()
      ->accessCheck(FALSE)
      ->condition('phase', $phase->id())
      ->condition('state', 'cancelled', '<>')
      ->count()
      ->execute();
    return ReadinessResult::fromReasons($count ? [] : [(string) $this->t('Phase @phase has no iteration.', ['@phase' => $phase->label()])]);
  }

}
