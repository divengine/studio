<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_database\Contract\Canonical;
use Drupal\divengine_database\Contract\ContractException;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Materializes a contract payload as an immutable snapshot of a database.
 *
 * Atomic: the facts are written in one transaction and the snapshot becomes
 * ready only when all of them are; a failure leaves nothing behind. The
 * identity is computed here from the facts, never trusted from the payload:
 * the same key with the same content is idempotent, the same key with other
 * content is refused, and content equal to the latest snapshot is reported
 * as unchanged. Findings are derived afterwards and never fail an import.
 * Callers check access; the importer trusts them.
 */
class SnapshotImporter {

  const CHUNK = 500;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FindingsAnalyzer $findings,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Imports a payload into a database.
   *
   * @param array $payload
   *   The contract (v1).
   * @param \Drupal\divengine_database\Entity\SchemaDatabase|null $target
   *   The database; by default the one whose identifier is the payload's
   *   database.code.
   * @param array $options
   *   - revision: the revision the schema was captured at (Git SHA, release…)
   *   - force: import even when the content equals the latest snapshot.
   *
   * @return array
   *   status (imported|idempotent|unchanged), snapshot, message.
   *
   * @throws \Drupal\divengine_database\Contract\ContractException
   */
  public function import(array $payload, ?SchemaDatabase $target = NULL, array $options = []): array {
    $this->validate($payload);
    $info = $payload['database'] ?? [];
    $target ??= $this->databaseFor((string) ($info['code'] ?? ''));
    $engine = mb_strtolower(trim((string) ($info['engine'] ?? '')));
    $known = (string) $target->get('engine')->value;
    if ($known !== '' && $engine !== '' && $engine !== 'unknown' && $known !== $engine) {
      throw new ContractException(sprintf('%s is a %s database; this snapshot comes from %s. Register another database for it.', $target->label(), $known, $engine));
    }
    if (!empty($options['revision'])) {
      $payload['snapshot']['source_revision'] = (string) $options['revision'];
    }
    $meta = $payload['snapshot'] ?? [];
    $hash = Canonical::contentHash($payload);
    $key = Canonical::snapshotKey($target->getIdentifier(), $meta['source_revision'] ?? NULL, $hash);

    $storage = $this->entityTypeManager->getStorage('schema_snapshot');
    $existing = $storage->loadByProperties(['snapshot_key' => $key]);
    $existing = reset($existing) ?: NULL;
    if ($existing instanceof SchemaSnapshot && $existing->isReady()) {
      if ($existing->get('content_hash')->value === $hash) {
        return ['status' => 'idempotent', 'snapshot' => $existing, 'message' => sprintf('%s was already imported with this content.', $key)];
      }
      throw new ContractException(sprintf('Snapshot %s already exists with other content. Give the changed schema its own revision.', $key));
    }
    $latest = $target->getLatestSnapshot();
    if ($latest && $latest->get('content_hash')->value === $hash && empty($options['force'])) {
      return ['status' => 'unchanged', 'snapshot' => $latest, 'message' => sprintf('The schema is the same as %s: nothing imported.', $latest->label())];
    }

    $transaction = $this->database->startTransaction();
    try {
      $existing?->delete();
      /** @var \Drupal\divengine_database\Entity\SchemaSnapshot $snapshot */
      $snapshot = $storage->create([
        'uid' => $this->currentUser->id(),
        'development' => $target->get('development')->target_id,
        'database' => $target->id(),
        'snapshot_key' => $key,
        'captured_at' => $this->timestamp($meta['captured_at'] ?? NULL),
        'source' => mb_substr((string) ($meta['source'] ?? 'contract'), 0, 64),
        'source_version' => mb_substr((string) ($meta['source_version'] ?? $meta['schemaspy_version'] ?? ''), 0, 64),
        'source_revision' => mb_substr((string) ($meta['source_revision'] ?? ''), 0, 128),
        'catalog' => mb_substr((string) ($meta['catalog'] ?? ''), 0, 255),
        'contract_version' => Canonical::CONTRACT_VERSION,
        'state' => 'draft',
      ]);
      $snapshot->save();
      $counts = $this->writeFacts((int) $snapshot->id(), $payload);
      foreach ($counts as $counter => $value) {
        $snapshot->set($counter, $value);
      }
      $snapshot->set('content_hash', $hash);
      $snapshot->set('state', 'ready');
      $snapshot->save();
      if ($known === '' && $engine !== '' && $engine !== 'unknown') {
        $target->set('engine', $engine)->save();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('Import of @key failed: @message', ['@key' => $key, '@message' => $e->getMessage()]);
      throw $e instanceof ContractException ? $e : new ContractException('The snapshot could not be imported: ' . $e->getMessage(), 0, $e);
    }
    unset($transaction);
    $this->analyze($snapshot);
    $this->logger->info('Imported @key into @database.', ['@key' => $key, '@database' => $target->label()]);
    return ['status' => 'imported', 'snapshot' => $snapshot, 'message' => sprintf('Imported %s: %d tables, %d columns, %d relationships.', $key, $snapshot->count('table_count') + $snapshot->count('view_count'), $snapshot->count('column_count'), $snapshot->count('real_fk_count') + $snapshot->count('implied_fk_count'))];
  }

  /**
   * (Re)computes the findings of a snapshot; a failure is recorded, not
   * thrown, because the schema itself is valid.
   */
  public function analyze(SchemaSnapshot $snapshot): void {
    try {
      $counts = $this->findings->analyze((int) $snapshot->id());
      $snapshot->set('import_error', NULL);
    }
    catch (\Throwable $e) {
      $this->logger->error('Findings of @key failed: @message', ['@key' => $snapshot->label(), '@message' => $e->getMessage()]);
      $counts = ['finding_count' => 0, 'finding_warning_count' => 0, 'finding_error_count' => 0];
      $snapshot->set('import_error', 'Findings could not be computed (the schema import is valid): ' . $e->getMessage());
    }
    foreach ($counts as $counter => $value) {
      $snapshot->set($counter, $value);
    }
    $snapshot->save();
  }

  /**
   * Whether an account may push snapshots into a database: managers who see
   * its Development, or analyzer accounts that are members of it.
   */
  public function mayImport(SchemaDatabase $database, AccountInterface $account): bool {
    $development = $database->getDevelopment();
    if (!$development) {
      return $account->hasPermission('administer database schemas');
    }
    if ($account->hasPermission('administer database schemas') || $account->hasPermission('manage database schemas')) {
      return $development->access('view', $account);
    }
    if (!$account->hasPermission('import database snapshots')) {
      return FALSE;
    }
    $people = array_merge(array_column($development->get('members')->getValue(), 'target_id'), [$development->get('responsible')->target_id]);
    return in_array((string) $account->id(), array_map('strval', $people), TRUE);
  }

  /**
   * The database registered under an identifier.
   */
  public function databaseFor(string $identifier): SchemaDatabase {
    if ($identifier === '') {
      throw new ContractException('The snapshot does not name its database (database.code): choose the database to import into.');
    }
    $found = $this->entityTypeManager->getStorage('schema_database')->loadByProperties(['identifier' => $identifier]);
    return reset($found) ?: throw new ContractException(sprintf('No database is registered with the identifier "%s". Register it in a Development first, or choose the database to import into.', $identifier));
  }

  /**
   * Structural checks: what the facts need to be written consistently.
   *
   * @throws \Drupal\divengine_database\Contract\ContractException
   */
  public function validate(array $payload): void {
    if (($payload['contract_version'] ?? NULL) !== Canonical::CONTRACT_VERSION) {
      throw new ContractException(sprintf('Unsupported contract_version %s (expected "%s").', json_encode($payload['contract_version'] ?? NULL), Canonical::CONTRACT_VERSION));
    }
    foreach (['tables', 'foreign_keys', 'routines'] as $collection) {
      if (isset($payload[$collection]) && (!is_array($payload[$collection]) || !array_is_list($payload[$collection]))) {
        throw new ContractException(sprintf('"%s" must be a list.', $collection));
      }
    }
    if (empty($payload['tables'])) {
      throw new ContractException('The snapshot has no tables.');
    }
    $tables = $columns = $indexes = $fks = [];
    foreach ($payload['tables'] as $i => $table) {
      if (!is_array($table) || trim((string) ($table['name'] ?? '')) === '') {
        throw new ContractException(sprintf('Table #%d has no name.', $i + 1));
      }
      $key = $this->tableKey($table);
      if (isset($tables[$key])) {
        throw new ContractException(sprintf('Table %s appears twice.', $key));
      }
      $tables[$key] = TRUE;
      foreach ($table['columns'] ?? [] as $column) {
        if (!is_array($column) || trim((string) ($column['name'] ?? '')) === '' || trim((string) ($column['logical_key'] ?? '')) === '') {
          throw new ContractException(sprintf('A column of %s has no name or logical_key.', $key));
        }
        if (isset($columns[$column['logical_key']])) {
          throw new ContractException(sprintf('Column %s appears twice.', $column['logical_key']));
        }
        $columns[$column['logical_key']] = TRUE;
      }
      foreach ($table['indexes'] ?? [] as $index) {
        $index_key = (string) ($index['logical_key'] ?? '');
        if ($index_key === '' || isset($indexes[$index_key])) {
          throw new ContractException(sprintf('An index of %s has no logical_key or repeats it.', $key));
        }
        $indexes[$index_key] = TRUE;
        foreach ($index['columns'] ?? [] as $member) {
          if (!isset($columns[$member['column_logical_key'] ?? ''])) {
            throw new ContractException(sprintf('Index %s refers to the unknown column %s.', $index['name'] ?? $index_key, $member['column_logical_key'] ?? '?'));
          }
        }
      }
    }
    foreach ($payload['foreign_keys'] ?? [] as $fk) {
      $fk_key = (string) ($fk['logical_key'] ?? '');
      if ($fk_key === '' || isset($fks[$fk_key])) {
        throw new ContractException(sprintf('Relationship %s has no logical_key or repeats it.', $fk['name'] ?? '?'));
      }
      $fks[$fk_key] = TRUE;
      if (!isset($tables[$fk['child_table_key'] ?? ''], $tables[$fk['parent_table_key'] ?? ''])) {
        throw new ContractException(sprintf('Relationship %s refers to an unknown table.', $fk['name'] ?? $fk_key));
      }
      foreach ($fk['columns'] ?? [] as $pair) {
        if (!isset($columns[$pair['child_column_key'] ?? ''], $columns[$pair['parent_column_key'] ?? ''])) {
          throw new ContractException(sprintf('Relationship %s refers to an unknown column.', $fk['name'] ?? $fk_key));
        }
      }
    }
  }

  protected function tableKey(array $table): string {
    return (string) ($table['logical_key'] ?? '') ?: ($table['catalog'] ?? '') . '.' . $table['name'];
  }

  /**
   * Writes every fact of the payload under a snapshot, in bulk.
   *
   * @return array
   *   The counters of the snapshot.
   */
  protected function writeFacts(int $sid, array $payload): array {
    $text = fn ($value) => $value === NULL || $value === '' || $value === FALSE ? NULL : (string) $value;
    $int = fn ($value) => is_numeric($value) ? (int) $value : NULL;
    $flag = fn ($value) => Canonical::truthy($value) ? 1 : 0;
    $name = fn ($value) => mb_substr((string) $value, 0, 255);
    $short = fn ($value) => $text($value) === NULL ? NULL : mb_substr((string) $value, 0, 128);

    $rows = [];
    foreach ($payload['tables'] as $table) {
      $rows[] = [
        'sid' => $sid,
        'name' => $name($table['name']),
        'catalog' => $name($table['catalog'] ?? ''),
        'obj_type' => ($table['obj_type'] ?? 'table') === 'view' ? 'view' : 'table',
        'comment' => $text($table['comment'] ?? NULL),
        'view_definition' => $text($table['view_definition'] ?? NULL),
        'logical_key' => $this->tableKey($table),
      ];
    }
    $table_ids = $this->insert('divengine_db_table', $rows, $sid);

    $rows = [];
    foreach ($payload['tables'] as $table) {
      $table_id = $table_ids[$this->tableKey($table)];
      foreach ($table['columns'] ?? [] as $column) {
        $rows[] = [
          'sid' => $sid,
          'table_id' => $table_id,
          'name' => $name($column['name']),
          'position' => $int($column['position'] ?? 0) ?? 0,
          'data_type' => $short($column['data_type'] ?? NULL),
          'jdbc_type_code' => $int($column['jdbc_type_code'] ?? NULL),
          'size' => $int($column['size'] ?? NULL),
          'scale' => $int($column['scale'] ?? NULL),
          'nullable' => $flag($column['nullable'] ?? FALSE),
          'has_default' => $flag($column['has_default'] ?? FALSE),
          'default_value' => isset($column['default_value']) ? (string) $column['default_value'] : NULL,
          'auto_updated' => $flag($column['auto_updated'] ?? FALSE),
          'comment' => $text($column['comment'] ?? NULL),
          'pk_position' => $int($column['pk_position'] ?? 0) ?? 0,
          'charset' => $short($column['charset'] ?? NULL),
          'collation' => $short($column['collation'] ?? NULL),
          'enum_values' => $text($column['enum_values'] ?? NULL),
          'auto_increment' => $flag($column['auto_increment'] ?? FALSE),
          'logical_key' => (string) $column['logical_key'],
        ];
      }
    }
    $column_ids = $this->insert('divengine_db_column', $rows, $sid);
    $pk_tables = [];
    foreach ($rows as $row) {
      if ($row['pk_position'] > 0) {
        $pk_tables[$row['table_id']] = TRUE;
      }
    }

    $rows = [];
    foreach ($payload['tables'] as $table) {
      foreach ($table['indexes'] ?? [] as $index) {
        $rows[] = [
          'sid' => $sid,
          'table_id' => $table_ids[$this->tableKey($table)],
          'name' => $name($index['name'] ?? ''),
          'is_unique' => $flag($index['is_unique'] ?? FALSE),
          'logical_key' => (string) $index['logical_key'],
        ];
      }
    }
    $index_ids = $this->insert('divengine_db_index', $rows, $sid);
    $rows = [];
    foreach ($payload['tables'] as $table) {
      foreach ($table['indexes'] ?? [] as $index) {
        foreach ($index['columns'] ?? [] as $member) {
          $rows[] = [
            'sid' => $sid,
            'index_id' => $index_ids[$index['logical_key']],
            'column_id' => $column_ids[$member['column_logical_key']],
            'position' => $int($member['position'] ?? 0) ?? 0,
            'ascending' => !array_key_exists('ascending', $member) || $member['ascending'] === NULL || Canonical::truthy($member['ascending']) ? 1 : 0,
          ];
        }
      }
    }
    $this->insert('divengine_db_index_column', $rows);

    $rows = [];
    $real = $implied = 0;
    foreach ($payload['foreign_keys'] ?? [] as $fk) {
      $is_implied = $flag($fk['implied'] ?? FALSE);
      $is_implied ? $implied++ : $real++;
      $rows[] = [
        'sid' => $sid,
        'name' => $name($fk['name'] ?? ''),
        'implied' => $is_implied,
        'on_delete_cascade' => $flag($fk['on_delete_cascade'] ?? FALSE),
        'on_update' => $short($fk['on_update'] ?? NULL),
        'child_table_id' => $table_ids[$fk['child_table_key']],
        'parent_table_id' => $table_ids[$fk['parent_table_key']],
        'logical_key' => (string) $fk['logical_key'],
      ];
    }
    $fk_ids = $this->insert('divengine_db_fk', $rows, $sid);
    $rows = [];
    foreach ($payload['foreign_keys'] ?? [] as $fk) {
      foreach ($fk['columns'] ?? [] as $pair) {
        $rows[] = [
          'sid' => $sid,
          'fk_id' => $fk_ids[$fk['logical_key']],
          'position' => $int($pair['position'] ?? 0) ?? 0,
          'child_column_id' => $column_ids[$pair['child_column_key']],
          'parent_column_id' => $column_ids[$pair['parent_column_key']],
        ];
      }
    }
    $this->insert('divengine_db_fk_column', $rows);

    $rows = [];
    foreach ($payload['routines'] ?? [] as $routine) {
      $type = ($routine['routine_type'] ?? '') === 'function' ? 'function' : 'procedure';
      $rows[] = [
        'sid' => $sid,
        'name' => $name($routine['name'] ?? ''),
        'catalog' => $name($routine['catalog'] ?? ''),
        'schema_name' => $name($routine['schema_name'] ?? ''),
        'routine_type' => $type,
        'return_type' => $short($routine['return_type'] ?? NULL),
        'parameter_signature' => $text($routine['parameter_signature'] ?? NULL),
        'definition' => $text($routine['definition'] ?? NULL),
        'deterministic' => $flag($routine['deterministic'] ?? FALSE),
        'sql_data_access' => $short($routine['sql_data_access'] ?? NULL),
        'security_type' => $short($routine['security_type'] ?? NULL),
        'comment' => $text($routine['comment'] ?? NULL),
        'created' => $short($routine['created'] ?? NULL),
        'modified' => $short($routine['modified'] ?? NULL),
        'logical_key' => (string) ($routine['logical_key'] ?? '') ?: ($routine['catalog'] ?? '') . '.routine:' . ($routine['name'] ?? '') . '(' . $type . ')',
      ];
    }
    $this->insert('divengine_db_routine', $rows);

    $views = count(array_filter($payload['tables'], fn ($t) => ($t['obj_type'] ?? 'table') === 'view'));
    return [
      'table_count' => count($payload['tables']) - $views,
      'view_count' => $views,
      'column_count' => count($column_ids),
      'index_count' => count($index_ids),
      'real_fk_count' => $real,
      'implied_fk_count' => $implied,
      'routine_count' => count($rows),
    ];
  }

  /**
   * Inserts rows in chunks; with a snapshot id, returns ids by logical key.
   */
  protected function insert(string $table, array $rows, ?int $sid = NULL): array {
    foreach (array_chunk($rows, static::CHUNK) as $chunk) {
      $query = $this->database->insert($table)->fields(array_keys($chunk[0]));
      foreach ($chunk as $row) {
        $query->values($row);
      }
      $query->execute();
    }
    if ($sid === NULL || !$rows) {
      return [];
    }
    return $this->database->select($table, 't')->fields('t', ['logical_key', 'id'])->condition('sid', $sid)->execute()->fetchAllKeyed();
  }

  protected function timestamp(mixed $value): int {
    if (is_int($value)) {
      return $value;
    }
    $value = trim((string) $value);
    if ($value !== '') {
      try {
        // Without a zone, producers mean UTC.
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
      }
      catch (\Exception) {
      }
    }
    return $this->time->getRequestTime();
  }

}
