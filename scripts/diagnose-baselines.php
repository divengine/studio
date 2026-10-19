<?php

/**
 * Read-only baseline integrity diagnostic. Run with drush php:script.
 *
 * Does not load/save entities, rebuild caches, repair or delete anything.
 * PostgreSQL enforces a read-only, repeatable-read transaction for all queries.
 * Output contains identifiers and counts, never titles or field contents.
 */

declare(strict_types=1);

$site_connection = \Drupal::database();
// Drush/bootstrap may already own a transaction. Use a fresh connection so the
// diagnostic controls the outermost transaction and its read-only isolation.
\Drupal\Core\Database\Database::addConnectionInfo('baseline_diagnostic', 'default', $site_connection->getConnectionOptions());
$db = \Drupal\Core\Database\Database::getConnection('default', 'baseline_diagnostic');
if ($db->driver() !== 'pgsql') {
  throw new \RuntimeException('This diagnostic requires PostgreSQL read-only transactions.');
}
$transaction = $db->startTransaction();
// PostgreSQL's Drupal driver wraps queries in savepoints. SET TRANSACTION must
// run on the outer transaction, before those statement-level savepoints.
$db->getClientConnection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$report = [
  'version' => 1,
  'read_only' => TRUE,
  'transaction_read_only' => $db->query('SHOW transaction_read_only')->fetchField(),
  'transaction_isolation' => $db->query('SHOW transaction_isolation')->fetchField(),
  'generated_at' => gmdate(DATE_ATOM),
  'baselines' => 0,
  'items' => 0,
  'issues' => [],
  'limitations' => [
    'No historical hashes or independent copies exist: in-place overwrites before this diagnostic cannot be ruled out.',
    'Empty baselines and revision timestamps after capture are review signals, not proof of corruption.',
    'Current identities, captured revisions and internal trace membership are checked; this is not a full configuration snapshot audit.',
  ],
];
$baselines = $db->select('baseline', 'b')->fields('b', ['id', 'development', 'created'])->execute()->fetchAllAssoc('id');
$report['baselines'] = count($baselines);
$items = [];
foreach ($db->select('divengine_process_baseline_item', 'i')->fields('i')->execute() as $item) {
  $items[$item->baseline_id][] = $item;
  $report['items']++;
}
$definitions = \Drupal::entityTypeManager();
foreach ($items as $baseline_id => $rows) {
  if (!isset($baselines[$baseline_id])) {
    $report['issues'][] = ['kind' => 'orphan_items', 'baseline_id' => (int) $baseline_id, 'count' => count($rows)];
  }
}
foreach ($baselines as $baseline_id => $baseline) {
  $rows = $items[$baseline_id] ?? [];
  if (!$rows) {
    $report['issues'][] = ['kind' => 'empty_baseline_review', 'baseline_id' => (int) $baseline_id];
  }
  $members = [];
  foreach ($rows as $item) {
    $members[$item->entity_type . ':' . $item->entity_id] = TRUE;
  }
  $seen = [];
  foreach ($rows as $item) {
    $ref = ['baseline_id' => (int) $baseline_id, 'entity_type' => $item->entity_type, 'entity_id' => (int) $item->entity_id, 'revision_id' => (int) $item->revision_id];
    $key = $item->entity_type . ':' . $item->entity_id;
    if (isset($seen[$key])) {
      $report['issues'][] = ['kind' => 'duplicate_member'] + $ref;
    }
    $seen[$key] = TRUE;
    if (!in_array($item->entity_type, ['artifact', 'trace_link'], TRUE)) {
      $report['issues'][] = ['kind' => 'unexpected_entity_type'] + $ref;
      continue;
    }
    $definition = $definitions->getDefinition($item->entity_type);
    $id = $definition->getKey('id');
    $revision_id = $definition->getKey('revision');
    $exists = $db->select($definition->getBaseTable(), 'e')->condition($id, $item->entity_id)->countQuery()->execute()->fetchField();
    if (!$exists) {
      $report['issues'][] = ['kind' => 'missing_entity'] + $ref;
    }
    $revision = $db->select($definition->getRevisionTable(), 'r')->fields('r')->condition($revision_id, $item->revision_id)->execute()->fetchObject();
    if (!$revision) {
      $report['issues'][] = ['kind' => 'missing_revision'] + $ref;
      continue;
    }
    if ((string) $revision->{$id} !== (string) $item->entity_id) {
      $report['issues'][] = ['kind' => 'revision_identity_mismatch'] + $ref;
      continue;
    }
    $data_table = $definition->getRevisionDataTable();
    if (!$data_table || !$db->schema()->tableExists($data_table)) {
      $data_table = $definition->getRevisionTable();
    }
    $data = $db->select($data_table, 'r')->fields('r')->condition($revision_id, $item->revision_id)->execute()->fetchObject();
    if (!$data) {
      $report['issues'][] = ['kind' => 'missing_revision_data'] + $ref;
      continue;
    }
    if ($item->entity_type === 'artifact' && (string) ($data->development ?? '') !== (string) $baseline->development) {
      $report['issues'][] = ['kind' => 'artifact_context_mismatch'] + $ref;
    }
    if ($item->entity_type === 'trace_link') {
      foreach (['source', 'target'] as $end) {
        $end_key = ($data->{$end . '_entity_type'} ?? '') . ':' . ($data->{$end . '_entity_id'} ?? '');
        if (!isset($members[$end_key])) {
          $report['issues'][] = ['kind' => 'trace_end_not_in_baseline', 'end' => $end] + $ref;
        }
      }
      if ((int) ($data->changed ?? 0) > (int) $baseline->created) {
        $report['issues'][] = ['kind' => 'trace_changed_after_capture_review'] + $ref;
      }
    }
  }
}
$report['issue_counts'] = array_count_values(array_column($report['issues'], 'kind'));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
// Explicit rollback keeps the diagnostic non-mutating even on normal exit.
$transaction->rollBack();
