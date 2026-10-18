<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Readiness;

use Drupal\divengine_process\Entity\DevelopmentInterface;

/**
 * A readiness criterion: one fact about a Development, met or not.
 *
 * Readiness explains; it never blocks a transition and has no score.
 */
interface ReadinessCriterionInterface {

  public function label(): string;

  /**
   * Evaluates the criterion.
   *
   * @param \Drupal\divengine_process\Entity\DevelopmentInterface $development
   *   The Development.
   * @param array<string, mixed> $line
   *   The policy line that asks for the criterion.
   * @param string|null $milestone_type
   *   The milestone being evaluated, or NULL for the whole Development.
   *
   * @return \Drupal\divengine_process\Readiness\ReadinessResult
   *   Whether it is met and, if not, why.
   */
  public function evaluate(DevelopmentInterface $development, array $line, ?string $milestone_type): ReadinessResult;

}
