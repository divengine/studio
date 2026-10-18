<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Readiness;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_process\DevelopmentProcess;
use Drupal\divengine_process\Entity\DevelopmentInterface;

/**
 * Evaluates the readiness lines of a Development's policy.
 *
 * Advisory and scoreless: every item says what is met and what is missing
 * and why; nothing is ever blocked by it.
 */
class ReadinessEvaluator {

  use StringTranslationTrait;

  const LEVEL_ORDER = ['required' => 0, 'recommended' => 1, 'optional' => 2];

  public function __construct(
    protected ReadinessCriterionManager $criteria,
    protected DevelopmentProcess $process,
  ) {}

  /**
   * Evaluates the Development, or one of its milestones.
   *
   * Without a milestone every readiness line counts; with one, lines for
   * another milestone are left out. A criterion whose module is not
   * installed is reported as unmet, never hidden.
   *
   * @return array<int, array{code: string, label: string, level: string, met: bool, reasons: string[], missing: bool}>
   */
  public function evaluate(DevelopmentInterface $development, ?string $milestone_type = NULL): array {
    $lines = $this->process->policyLines($development, 'readiness');
    usort($lines, fn ($a, $b) => [static::LEVEL_ORDER[$a['level']] ?? 9, $a['weight'] ?? 0] <=> [static::LEVEL_ORDER[$b['level']] ?? 9, $b['weight'] ?? 0]);
    $items = [];
    foreach ($lines as $line) {
      if ($milestone_type && ($line['milestone_type'] ?? '') !== '' && $line['milestone_type'] !== $milestone_type) {
        continue;
      }
      $code = $line['target'];
      if (!$this->criteria->hasDefinition($code)) {
        $items[] = [
          'code' => $code,
          'label' => $code,
          'level' => $line['level'],
          'met' => FALSE,
          'reasons' => [(string) $this->t("This criterion's module is not installed.")],
          'missing' => TRUE,
        ];
        continue;
      }
      /** @var \Drupal\divengine_process\Readiness\ReadinessCriterionInterface $criterion */
      $criterion = $this->criteria->createInstance($code);
      $result = $criterion->evaluate($development, $line, $milestone_type);
      $items[] = [
        'code' => $code,
        'label' => $criterion->label(),
        'level' => $line['level'],
        'met' => $result->met,
        'reasons' => $result->reasons,
        'missing' => FALSE,
      ];
    }
    return $items;
  }

  /**
   * How many required items are not met.
   */
  public function missingCount(array $items): int {
    return count(array_filter($items, fn (array $item) => $item['level'] === 'required' && !$item['met']));
  }

}
