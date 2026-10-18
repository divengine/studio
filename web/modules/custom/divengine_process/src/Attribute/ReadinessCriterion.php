<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a readiness criterion plugin.
 *
 * Development Case lines refer to criteria by plugin ID.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ReadinessCriterion extends Plugin {

  /**
   * @param string $id
   *   The criterion code, e.g. "iterations_planned".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   What the criterion asks for, phrased as a fact.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?string $deriver = NULL,
  ) {}

}
