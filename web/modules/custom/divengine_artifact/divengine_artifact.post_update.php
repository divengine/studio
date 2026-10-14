<?php

/**
 * @file
 * Post update functions for Divengine Artifact.
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;

/**
 * Install the artifact lifecycle workflow on existing sites.
 */
function divengine_artifact_post_update_artifact_lifecycle_workflow(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('workflow');
  if ($storage->load('artifact_lifecycle')) {
    return (string) t('The artifact lifecycle workflow already exists.');
  }
  $path = \Drupal::service('extension.list.module')->getPath('divengine_artifact');
  $data = (new FileStorage($path . '/config/install'))->read('workflows.workflow.artifact_lifecycle');
  // Attach only the artifact types this site still has.
  $data['type_settings']['entity_types']['artifact'] = array_values(array_intersect(
    $data['type_settings']['entity_types']['artifact'],
    array_keys(\Drupal::entityTypeManager()->getStorage('artifact_type')->loadMultiple()),
  ));
  $storage->createFromStorageRecord($data)->save();
  return (string) t('Installed the artifact lifecycle workflow.');
}

/**
 * Install the artifact listing view on existing sites.
 */
function divengine_artifact_post_update_artifact_listing_view(): string {
  \Drupal::service('config.installer')->installOptionalConfig();
  return (string) t('Installed optional configuration, including the artifact listing view.');
}
