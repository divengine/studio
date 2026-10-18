<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Readiness;

/**
 * The outcome of a readiness criterion.
 */
final class ReadinessResult {

  /**
   * @param bool $met
   *   Whether the criterion is met.
   * @param string[] $reasons
   *   Why it is not met, one sentence each, naming the records concerned.
   */
  public function __construct(
    public readonly bool $met,
    public readonly array $reasons = [],
  ) {}

  /**
   * Met when there is nothing to report.
   *
   * @param string[] $reasons
   */
  public static function fromReasons(array $reasons): static {
    return new static(!$reasons, array_values($reasons));
  }

}
