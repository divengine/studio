<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Lifecycle;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One allowed move of a lifecycle: from some states to one state.
 *
 * The guard and the effects are method names of the entity. A guard returns
 * NULL when the move is allowed, or the reason it is not; the effect runs
 * after the state is set and before the entity is validated and saved (it
 * only changes the entity itself); the after-save effect may change other
 * records.
 */
final class Transition {

  /**
   * @param string $id
   *   Machine name, e.g. "activate".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Button label.
   * @param string[] $from
   *   States the transition leaves from.
   * @param string $to
   *   The resulting state.
   * @param string|null $guard
   *   Entity method returning NULL or a TranslatableMarkup reason.
   * @param string|null $effect
   *   Entity method run after the state changes, before saving.
   * @param string|null $after
   *   Entity method run once the entity is saved.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $confirm
   *   Question to confirm before applying, if any.
   * @param string|null $field
   *   A field the user fills in when applying the transition (a block
   *   reason, a decision rationale, a target iteration); asking for it makes
   *   the transition go through a confirmation form.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $from,
    public readonly string $to,
    public readonly ?string $guard = NULL,
    public readonly ?string $effect = NULL,
    public readonly ?string $after = NULL,
    public readonly ?TranslatableMarkup $confirm = NULL,
    public readonly ?string $field = NULL,
  ) {}

}
