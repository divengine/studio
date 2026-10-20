<?php

/**
 * @file
 * Hooks of the Unified Process module.
 */

/**
 * Declares fields other than "development" that tie records to a Development.
 *
 * Records of these types keep a Development from being deleted, and trace
 * rules resolve their Development through the field. Types whose rows follow
 * the visibility of a Development already appear with their "development"
 * field.
 *
 * @param array<string, string> $references
 *   Field names keyed by entity type ID.
 */
function hook_divengine_process_development_references_alter(array &$references): void {
  $references['node'] = 'kb_development';
}

/**
 * Adds entity types whose rows follow the visibility of their Development.
 *
 * @param string[] $types
 *   Entity type IDs with a "development" base field.
 */
function hook_divengine_process_scoped_types_alter(array &$types): void {
  $types[] = 'source_repository';
}
