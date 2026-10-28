<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\divengine_external\Entity\ExternalReference;

/**
 * A provider that can read the current state of public work, read-only.
 *
 * Implementations contact only their provider's API host, build request
 * URLs from the stored identity (never from a stored link), send no
 * credentials, follow no redirects and bound time and size.
 */
interface ObservableProviderInterface extends ExternalProviderInterface {

  /**
   * Asks the provider about the work. Never throws for network errors.
   */
  public function observe(ExternalReference $reference): ExternalObservation;

}
