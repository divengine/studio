<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Plugin\ReadinessCriterion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_process\Attribute\ReadinessCriterion;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Drupal\divengine_process\Readiness\ReadinessCriterionBase;
use Drupal\divengine_process\Readiness\ReadinessResult;

/**
 * Someone plays a role of every required discipline.
 *
 * A discipline that no process role covers counts as staffed.
 */
#[ReadinessCriterion(
  id: 'disciplines_staffed',
  label: new TranslatableMarkup('Required disciplines have someone in their roles'),
)]
class DisciplinesStaffed extends ReadinessCriterionBase {

  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult {
    $played = [];
    foreach ($this->process->children($development, 'role_assignment') as $assignment) {
      $played[$assignment->get('process_role')->target_id] = TRUE;
    }
    $roles = array_filter($this->entityTypeManager->getStorage('process_role')->loadMultiple(), fn ($role) => $role->status());
    $reasons = [];
    foreach ($this->process->policyLines($development, 'discipline', ['required']) as $discipline_line) {
      $covering = array_filter($roles, fn ($role) => in_array($discipline_line['target'], $role->getDisciplines(), TRUE));
      if ($covering && !array_intersect_key($covering, $played)) {
        $discipline = $this->entityTypeManager->getStorage('process_discipline')->load($discipline_line['target']);
        $reasons[] = (string) $this->t('Nobody plays a role of discipline @discipline (@roles).', [
          '@discipline' => $discipline?->label() ?? $discipline_line['target'],
          '@roles' => implode(', ', array_map(fn ($role) => $role->label(), $covering)),
        ]);
      }
    }
    return ReadinessResult::fromReasons($reasons);
  }

}
