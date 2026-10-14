<?php

/**
 * @file
 * Post update functions for Divengine Traceability.
 */

declare(strict_types=1);

/**
 * Install the trace link listing view on existing sites.
 */
function divengine_traceability_post_update_trace_link_listing_view(): string {
  \Drupal::service('config.installer')->installOptionalConfig();
  return (string) t('Installed optional configuration, including the trace link listing view.');
}
