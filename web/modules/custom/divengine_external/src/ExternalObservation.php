<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * What a provider said about external work when Studio last asked.
 *
 * It is the provider's state, observed at a moment; it never changes
 * Studio's own records.
 */
final class ExternalObservation {

  /**
   * The provider answered and the work exists.
   */
  const OK = 'ok';

  /**
   * The work does not exist, or is not public.
   */
  const NOT_FOUND = 'not_found';

  /**
   * The provider asked Studio to wait.
   */
  const RATE_LIMITED = 'rate_limited';

  /**
   * The provider could not be reached or gave an unusable answer.
   */
  const UNAVAILABLE = 'unavailable';

  /**
   * @param string $status
   *   One of the constants above.
   * @param string $title
   *   The title the provider gives the work, plain text.
   * @param string $state
   *   Its state on the provider, e.g. "open", "closed", "merged", "success".
   * @param int|null $updated
   *   When the provider says it last changed.
   * @param string $note
   *   A remark for people, e.g. that the issue number is a pull request.
   * @param int|null $retryAfter
   *   When the provider may be asked again, for RATE_LIMITED.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $title = '',
    public readonly string $state = '',
    public readonly ?int $updated = NULL,
    public readonly string $note = '',
    public readonly ?int $retryAfter = NULL,
  ) {}

}
