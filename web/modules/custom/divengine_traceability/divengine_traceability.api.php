<?php

/**
 * @file
 * Hooks of the Traceability module.
 */

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Adds entity types that can be ends of trace links.
 *
 * They are offered in the trace link form, and their pages show the Traces
 * panel.
 *
 * @param string[] $types
 *   Entity type IDs.
 */
function hook_divengine_trace_end_types_alter(array &$types): void {
  $types[] = 'source_repository';
}

/**
 * Adds actions to the Traces panel of a record.
 *
 * @param array $actions
 *   Render arrays keyed by name; "add" is the Add trace button when the
 *   viewer may create trace links.
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The record whose panel is built.
 */
function hook_divengine_trace_panel_actions_alter(array &$actions, ContentEntityInterface $entity): void {
  $actions['external'] = [
    '#type' => 'link',
    '#title' => t('Link external work'),
    '#url' => \Drupal\Core\Url::fromRoute('divengine_external.link', ['entity_type_id' => $entity->getEntityTypeId(), 'entity_id' => $entity->id()]),
  ];
}
