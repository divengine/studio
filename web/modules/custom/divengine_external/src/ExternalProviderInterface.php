<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

/**
 * Reads the identity of external work from its links.
 *
 * Providers never contact the network to recognize a link: a reference must
 * be registrable, and readable, while the provider is unreachable.
 */
interface ExternalProviderInterface {

  /**
   * The identity of the work a link points to.
   *
   * @param \Drupal\divengine_external\ExternalUrl $url
   *   The link, already checked and normalized.
   *
   * @throws \Drupal\divengine_external\InvalidExternalUrlException
   *   When the link is on this provider but is not a kind of work it
   *   recognizes.
   */
  public function identify(ExternalUrl $url): ExternalIdentity;

  /**
   * Human labels of the kinds of work this provider recognizes, by kind.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public function kinds(): array;

}
