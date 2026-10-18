<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Every required activity definition was executed: completed or skipped.
 */
#[ReadinessCriterion(
  id: 'activities_completed',
  label: new TranslatableMarkup('Required activities are completed or explicitly skipped'),
)]
class ActivitiesCompleted extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $planned = $done = [];
    foreach ($this->process->children($development, 'process_activity') as $activity) {
      $definition = $activity->get('definition')->target_id;
      if ($definition) {
        $planned[$definition] = TRUE;
        if (in_array($activity->get('state')->value, ['completed', 'skipped'], TRUE)) {
          $done[$definition] = TRUE;
        }
      }
    }
    $reasons = [];
    foreach ($this->requiredElements($development, 'activity_definition', $line, $milestone_type) as $element) {
      $label = $this->entityTypeManager->getStorage('activity_definition')->load($element['target'])?->label() ?? $element['target'];
      if (!isset($planned[$element['target']])) {
        $reasons[$element['target']] = (string) $this->t('Activity @definition is not planned.', ['@definition' => $label]);
      }
      elseif (!isset($done[$element['target']])) {
        $reasons[$element['target']] = (string) $this->t('Activity @definition is not completed.', ['@definition' => $label]);
      }
    }
    return ReadinessResult::fromReasons($reasons);
  }

}
