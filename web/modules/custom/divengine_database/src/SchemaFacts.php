<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\Core\Database\Connection;
use Drupal\divengine_database\Entity\SchemaSnapshot;

/**
 * Reads the facts of snapshots: the schema explorer's API, the pages and the
 * input of the schema diff.
 *
 * Only captured facts are returned. Derived values are labelled: a
 * relationship's cardinality comes from PK / unique-index coverage of its
 * columns, its optionality from their nullability.
 */
class SchemaFacts {

  /**
   * Ceiling of tables per details call: the explorer asks in batches.
   */
  const MAX_DETAIL_TABLES = 400;

  const MAX_SEARCH_RESULTS = 200;

  const FACT_TABLES = ['divengine_db_table', 'divengine_db_column', 'divengine_db_index', 'divengine_db_index_column', 'divengine_db_fk', 'divengine_db_fk_column', 'divengine_db_routine', 'divengine_db_finding'];

  public function __construct(protected Connection $database) {}

  /**
   * Forgets the facts of deleted snapshots.
   */
  public function forget(array $sids): void {
    if (!$sids) {
      return;
    }
    foreach (static::FACT_TABLES as $table) {
      $this->database->delete($table)->condition('sid', $sids, 'IN')->execute();
    }
  }

  /**
   * A compact type label: varchar(36), int, decimal(10,2).
   */
  public static function shortType(?string $type, mixed $size, mixed $scale): string {
    $type = mb_strtolower((string) $type);
    if ($size && in_array($type, ['varchar', 'char', 'varbinary', 'binary', 'character varying', 'character', 'bpchar'], TRUE)) {
      return "$type($size)";
    }
    if ($size && in_array($type, ['decimal', 'numeric'], TRUE)) {
      return sprintf('%s(%s,%s)', $type, $size, $scale ?: 0);
    }
    return $type !== '' ? $type : '?';
  }

  /**
   * The snapshot as the explorer and the diff describe it.
   */
  public function snapshotMeta(SchemaSnapshot $snapshot): array {
    $database = $snapshot->getDatabase();
    return [
      'id' => (int) $snapshot->id(),
      'key' => $snapshot->label(),
      'database_id' => $database ? (int) $database->id() : NULL,
      'database_code' => $database?->get('code')->value,
      'database_name' => $database?->get('name')->value,
      'engine' => $database?->get('engine')->value,
      'catalog' => (string) $snapshot->get('catalog')->value,
      'source_revision' => $snapshot->get('source_revision')->value ?: NULL,
      'captured_at' => $snapshot->get('captured_at')->value ? gmdate('c', (int) $snapshot->get('captured_at')->value) : NULL,
      'state' => $snapshot->get('state')->value,
    ];
  }

  /**
   * Column sets of the unique indexes (PRIMARY included), by table id.
   */
  protected function uniqueColumnSets(int $sid, ?array $table_ids = NULL): array {
    $query = $this->database->select('divengine_db_index_column', 'm')->fields('m', ['index_id', 'column_id']);
    $query->innerJoin('divengine_db_index', 'i', 'i.id = m.index_id');
    $query->addField('i', 'table_id');
    $query->condition('m.sid', $sid)->condition('i.is_unique', 1);
    if ($table_ids !== NULL) {
      $query->condition('i.table_id', $table_ids ?: [0], 'IN');
    }
    $by_index = [];
    foreach ($query->execute() as $row) {
      $by_index[$row->index_id]['table'] = (int) $row->table_id;
      $by_index[$row->index_id]['columns'][] = (int) $row->column_id;
    }
    $out = [];
    foreach ($by_index as $index) {
      $out[$index['table']][] = $index['columns'];
    }
    return $out;
  }

  /**
   * The whole snapshot as a skeleton: tables with counts, every relationship
   * with its column pairs, no column rows.
   */
  public function graphIndex(SchemaSnapshot $snapshot): array {
    $sid = (int) $snapshot->id();
    $tables = $this->database->select('divengine_db_table', 't')->fields('t', ['id', 'name', 'catalog', 'obj_type', 'logical_key'])->condition('sid', $sid)->orderBy('name')->execute()->fetchAll();
    $counts = $this->database->query('SELECT table_id, COUNT(*) AS n, SUM(CASE WHEN pk_position > 0 THEN 1 ELSE 0 END) AS pk FROM {divengine_db_column} WHERE sid = :sid GROUP BY table_id', [':sid' => $sid])->fetchAllAssoc('table_id');

    $fks = $this->database->select('divengine_db_fk', 'f')->fields('f')->condition('sid', $sid)->orderBy('id')->execute()->fetchAll();
    $query = $this->database->select('divengine_db_fk_column', 'm')->fields('m', ['fk_id', 'child_column_id', 'parent_column_id']);
    $query->leftJoin('divengine_db_column', 'c', 'c.id = m.child_column_id');
    $query->leftJoin('divengine_db_column', 'p', 'p.id = m.parent_column_id');
    $query->addField('c', 'name', 'child_name');
    $query->addField('c', 'nullable', 'child_nullable');
    $query->addField('p', 'name', 'parent_name');
    $pairs = [];
    foreach ($query->condition('m.sid', $sid)->orderBy('m.fk_id')->orderBy('m.position')->execute() as $m) {
      $pairs[$m->fk_id][] = $m;
    }
    $unique = $this->uniqueColumnSets($sid);
    $fk_columns = [];
    $relations = [];
    foreach ($fks as $fk) {
      $members = $pairs[$fk->id] ?? [];
      $source_columns = array_map(fn ($m) => (int) $m->child_column_id, $members);
      $child = (int) $fk->child_table_id;
      $one_to_one = FALSE;
      foreach ($source_columns ? ($unique[$child] ?? []) : [] as $set) {
        if (!array_diff($set, $source_columns)) {
          $one_to_one = TRUE;
          break;
        }
      }
      $relations[] = [
        'id' => (int) $fk->id,
        'key' => $fk->logical_key,
        'name' => $fk->name,
        'implied' => (bool) $fk->implied,
        'on_delete_cascade' => (bool) $fk->on_delete_cascade,
        'on_update' => $fk->on_update ?: NULL,
        'source' => $child,
        'target' => (int) $fk->parent_table_id,
        'columns' => array_map(fn ($m) => ['source' => (int) $m->child_column_id, 'source_name' => $m->child_name, 'target' => (int) $m->parent_column_id, 'target_name' => $m->parent_name], $members),
        'cardinality' => $one_to_one ? 'one-to-one' : 'many-to-one',
        'optional' => (bool) array_filter($members, fn ($m) => (bool) $m->child_nullable),
      ];
      if (!$fk->implied) {
        foreach ($source_columns as $column) {
          $fk_columns[$child][$column] = TRUE;
        }
      }
    }
    $out = array_map(fn ($t) => [
      'id' => (int) $t->id,
      'key' => $t->logical_key,
      'name' => $t->name,
      'schema' => (string) $t->catalog,
      'type' => $t->obj_type ?: 'table',
      'columns' => (int) ($counts[$t->id]->n ?? 0),
      'pk' => (int) ($counts[$t->id]->pk ?? 0),
      'fk' => count($fk_columns[$t->id] ?? []),
    ], $tables);
    $real = count(array_filter($relations, fn ($r) => !$r['implied']));
    return [
      'snapshot' => $this->snapshotMeta($snapshot),
      'tables' => $out,
      'relations' => $relations,
      'stats' => [
        'tables' => count(array_filter($out, fn ($t) => $t['type'] === 'table')),
        'views' => count(array_filter($out, fn ($t) => $t['type'] === 'view')),
        'columns' => array_sum(array_column($out, 'columns')),
        'relations' => count($relations),
        'real' => $real,
        'implied' => count($relations) - $real,
      ],
    ];
  }

  /**
   * Column rows of a bounded set of tables.
   */
  public function tableDetails(SchemaSnapshot $snapshot, array $table_ids): array {
    $sid = (int) $snapshot->id();
    $ids = array_slice(array_values(array_unique(array_map('intval', $table_ids))), 0, static::MAX_DETAIL_TABLES);
    if (!$ids) {
      return ['tables' => (object) []];
    }
    $tables = $this->database->select('divengine_db_table', 't')->fields('t', ['id', 'obj_type', 'comment', 'view_definition'])->condition('sid', $sid)->condition('id', $ids, 'IN')->execute()->fetchAllAssoc('id');
    $valid = array_keys($tables) ?: [0];
    $columns = $this->database->select('divengine_db_column', 'c')->fields('c')->condition('table_id', $valid, 'IN')->orderBy('table_id')->orderBy('position')->execute()->fetchAll();

    $query = $this->database->select('divengine_db_fk_column', 'm')->fields('m', ['child_column_id']);
    $query->innerJoin('divengine_db_fk', 'f', 'f.id = m.fk_id');
    $query->addField('f', 'implied');
    $query->innerJoin('divengine_db_column', 'c', 'c.id = m.child_column_id');
    $real = $implied = [];
    foreach ($query->condition('c.table_id', $valid, 'IN')->execute() as $m) {
      if ($m->implied) {
        $implied[$m->child_column_id] = TRUE;
      }
      else {
        $real[$m->child_column_id] = TRUE;
      }
    }
    $single = $part = [];
    foreach ($this->uniqueColumnSets($sid, $valid) as $sets) {
      foreach ($sets as $set) {
        foreach ($set as $column) {
          count($set) === 1 ? $single[$column] = TRUE : $part[$column] = TRUE;
        }
      }
    }
    $out = [];
    foreach ($tables as $id => $table) {
      $out[$id] = ['type' => $table->obj_type, 'comment' => $table->comment ?: NULL, 'view_definition' => $table->view_definition ?: NULL, 'columns' => []];
    }
    foreach ($columns as $c) {
      $id = (int) $c->id;
      $out[$c->table_id]['columns'][] = [
        'id' => $id,
        'name' => $c->name,
        'position' => (int) $c->position,
        'type' => static::shortType($c->data_type, $c->size, $c->scale),
        'data_type' => $c->data_type ?: NULL,
        'nullable' => (bool) $c->nullable,
        'default' => $c->has_default ? $c->default_value : NULL,
        'has_default' => (bool) $c->has_default,
        'pk' => (int) $c->pk_position,
        'fk' => isset($real[$id]),
        'implied_fk' => isset($implied[$id]) && !isset($real[$id]),
        'unique' => isset($single[$id]) ? 'unique' : (isset($part[$id]) ? 'part' : FALSE),
        'auto_increment' => (bool) $c->auto_increment,
        'enum' => $c->enum_values ?: NULL,
        'comment' => $c->comment ?: NULL,
      ];
    }
    return ['tables' => (object) $out];
  }

  /**
   * Tables by name, schema and column name; table names first.
   */
  public function search(SchemaSnapshot $snapshot, string $text, int $limit = 50): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    $sid = (int) $snapshot->id();
    $limit = max(1, min($limit, static::MAX_SEARCH_RESULTS));
    $like = '%' . $this->database->escapeLike($text) . '%';
    $hits = [];
    $seen = [];
    foreach ($this->database->select('divengine_db_table', 't')->fields('t', ['id', 'name'])->condition('sid', $sid)->condition('name', $like, 'LIKE')->orderBy('name')->range(0, $limit)->execute() as $t) {
      $hits[] = ['table_id' => (int) $t->id, 'kind' => 'table', 'text' => $t->name];
      $seen[$t->id] = TRUE;
    }
    if (count($hits) < $limit) {
      foreach ($this->database->select('divengine_db_table', 't')->fields('t', ['id', 'name', 'catalog'])->condition('sid', $sid)->condition('catalog', $like, 'LIKE')->orderBy('name')->range(0, $limit - count($hits))->execute() as $t) {
        if (!isset($seen[$t->id])) {
          $hits[] = ['table_id' => (int) $t->id, 'kind' => 'schema', 'text' => $t->catalog . '.' . $t->name];
          $seen[$t->id] = TRUE;
        }
      }
    }
    if (count($hits) < $limit) {
      $query = $this->database->select('divengine_db_column', 'c')->fields('c', ['table_id', 'name']);
      $query->innerJoin('divengine_db_table', 't', 't.id = c.table_id');
      $query->addField('t', 'name', 'table_name');
      foreach ($query->condition('c.sid', $sid)->condition('c.name', $like, 'LIKE')->orderBy('t.name')->orderBy('c.name')->range(0, $limit - count($hits))->execute() as $c) {
        $hits[] = ['table_id' => (int) $c->table_id, 'kind' => 'column', 'text' => $c->table_name . '.' . $c->name, 'column' => $c->name];
      }
    }
    return array_slice($hits, 0, $limit);
  }

  /**
   * One table of a snapshot, or NULL.
   */
  public function table(int $sid, int $id): ?object {
    return $this->database->select('divengine_db_table', 't')->fields('t')->condition('sid', $sid)->condition('id', $id)->execute()->fetchObject() ?: NULL;
  }

  /**
   * Tables of a snapshot with their counts, optionally filtered by name.
   */
  public function tables(int $sid, string $filter = '', int $limit = 0): array {
    $query = $this->database->select('divengine_db_table', 't')->fields('t', ['id', 'name', 'catalog', 'obj_type', 'comment'])->condition('t.sid', $sid)->orderBy('t.name');
    if (trim($filter) !== '') {
      $query->condition('t.name', '%' . $this->database->escapeLike(trim($filter)) . '%', 'LIKE');
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    $tables = $query->execute()->fetchAllAssoc('id');
    if (!$tables) {
      return [];
    }
    $ids = array_keys($tables);
    $columns = $this->database->query('SELECT table_id, COUNT(*) AS n FROM {divengine_db_column} WHERE table_id IN (:ids[]) GROUP BY table_id', [':ids[]' => $ids])->fetchAllKeyed();
    $out = $this->database->query('SELECT child_table_id, COUNT(*) AS n FROM {divengine_db_fk} WHERE child_table_id IN (:ids[]) GROUP BY child_table_id', [':ids[]' => $ids])->fetchAllKeyed();
    $in = $this->database->query('SELECT parent_table_id, COUNT(*) AS n FROM {divengine_db_fk} WHERE parent_table_id IN (:ids[]) GROUP BY parent_table_id', [':ids[]' => $ids])->fetchAllKeyed();
    $findings = $this->database->query('SELECT table_id, COUNT(*) AS n FROM {divengine_db_finding} WHERE table_id IN (:ids[]) GROUP BY table_id', [':ids[]' => $ids])->fetchAllKeyed();
    foreach ($tables as $id => $table) {
      $table->columns = (int) ($columns[$id] ?? 0);
      $table->references = (int) ($out[$id] ?? 0);
      $table->referenced_by = (int) ($in[$id] ?? 0);
      $table->findings = (int) ($findings[$id] ?? 0);
    }
    return $tables;
  }

  /**
   * Everything about one table: columns, indexes, relationships both ways.
   */
  public function tableFacts(object $table): array {
    $details = [];
    foreach ($this->database->select('divengine_db_column', 'c')->fields('c')->condition('table_id', $table->id)->orderBy('position')->execute() as $c) {
      $details[$c->id] = $c;
    }
    $indexes = [];
    $query = $this->database->select('divengine_db_index', 'i')->fields('i', ['id', 'name', 'is_unique']);
    $query->leftJoin('divengine_db_index_column', 'm', 'm.index_id = i.id');
    $query->leftJoin('divengine_db_column', 'c', 'c.id = m.column_id');
    $query->addField('c', 'name', 'column_name');
    $query->addField('m', 'ascending');
    foreach ($query->condition('i.table_id', $table->id)->orderBy('i.name')->orderBy('m.position')->execute() as $row) {
      $indexes[$row->id] ??= (object) ['name' => $row->name, 'unique' => (bool) $row->is_unique, 'columns' => []];
      if ($row->column_name !== NULL) {
        $indexes[$row->id]->columns[] = $row->column_name . ($row->ascending ? '' : ' DESC');
      }
    }
    return [
      'columns' => $details,
      'indexes' => $indexes,
      'references' => $this->relationships('child_table_id', (int) $table->id),
      'referenced_by' => $this->relationships('parent_table_id', (int) $table->id),
    ];
  }

  protected function relationships(string $side, int $table_id): array {
    $query = $this->database->select('divengine_db_fk', 'f')->fields('f');
    $query->innerJoin('divengine_db_table', 'c', 'c.id = f.child_table_id');
    $query->innerJoin('divengine_db_table', 'p', 'p.id = f.parent_table_id');
    $query->addField('c', 'name', 'child_name');
    $query->addField('p', 'name', 'parent_name');
    $fks = $query->condition("f.$side", $table_id)->orderBy('f.implied')->orderBy('f.name')->execute()->fetchAllAssoc('id');
    if ($fks) {
      $query = $this->database->select('divengine_db_fk_column', 'm')->fields('m', ['fk_id']);
      $query->leftJoin('divengine_db_column', 'c', 'c.id = m.child_column_id');
      $query->leftJoin('divengine_db_column', 'p', 'p.id = m.parent_column_id');
      $query->addField('c', 'name', 'child_column');
      $query->addField('p', 'name', 'parent_column');
      foreach ($query->condition('m.fk_id', array_keys($fks), 'IN')->orderBy('m.position')->execute() as $pair) {
        $fks[$pair->fk_id]->pairs[] = [$pair->child_column, $pair->parent_column];
      }
    }
    return $fks;
  }

  /**
   * Findings of a snapshot, optionally of one table, most severe first.
   */
  public function findings(int $sid, ?int $table_id = NULL): array {
    $query = $this->database->select('divengine_db_finding', 'f')->fields('f')->condition('f.sid', $sid);
    $query->leftJoin('divengine_db_table', 't', 't.id = f.table_id');
    $query->addField('t', 'name', 'table_name');
    if ($table_id !== NULL) {
      $query->condition('f.table_id', $table_id);
    }
    $query->addExpression("CASE f.severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END", 'rank');
    return $query->orderBy('rank')->orderBy('f.rule_code')->orderBy('t.name')->execute()->fetchAll();
  }

  /**
   * Routines of a snapshot.
   */
  public function routines(int $sid): array {
    return $this->database->select('divengine_db_routine', 'r')->fields('r')->condition('sid', $sid)->orderBy('routine_type')->orderBy('name')->execute()->fetchAll();
  }

  /**
   * A snapshot as the diff engine's normalized input.
   */
  public function diffInput(SchemaSnapshot $snapshot): array {
    $sid = (int) $snapshot->id();
    $select = fn (string $table) => $this->database->select($table, 'x')->fields('x')->condition('sid', $sid)->orderBy('id')->execute()->fetchAll();
    $column_by_id = [];
    $columns_by_table = [];
    foreach ($select('divengine_db_column') as $c) {
      $column_by_id[$c->id] = $c;
      $columns_by_table[$c->table_id][] = [
        'id' => (int) $c->id,
        'name' => $c->name,
        'position' => (int) $c->position,
        'data_type' => $c->data_type,
        'size' => $c->size === NULL ? NULL : (int) $c->size,
        'scale' => $c->scale === NULL ? NULL : (int) $c->scale,
        'nullable' => (bool) $c->nullable,
        'has_default' => (bool) $c->has_default,
        'default' => $c->default_value,
        'auto_increment' => (bool) $c->auto_increment,
        'auto_updated' => (bool) $c->auto_updated,
        'charset' => $c->charset,
        'collation' => $c->collation,
        'enum_values' => $c->enum_values,
        'comment' => $c->comment,
        'pk_position' => (int) $c->pk_position,
      ];
    }
    $members = [];
    $index_columns = $select('divengine_db_index_column');
    usort($index_columns, fn ($a, $b) => $a->position <=> $b->position ?: $a->id <=> $b->id);
    foreach ($index_columns as $m) {
      if (isset($column_by_id[$m->column_id])) {
        $members[$m->index_id][] = ['name' => $column_by_id[$m->column_id]->name, 'asc' => (bool) $m->ascending];
      }
    }
    $indexes = [];
    foreach ($select('divengine_db_index') as $ix) {
      $indexes[$ix->table_id][] = ['id' => (int) $ix->id, 'name' => $ix->name, 'unique' => (bool) $ix->is_unique, 'columns' => $members[$ix->id] ?? []];
    }
    $tables = [];
    foreach ($select('divengine_db_table') as $t) {
      $tables[$t->id] = [
        'id' => (int) $t->id,
        'schema' => (string) $t->catalog,
        'name' => $t->name,
        'type' => $t->obj_type ?: 'table',
        'comment' => $t->comment,
        'view_definition' => $t->view_definition,
        'columns' => $columns_by_table[$t->id] ?? [],
        'indexes' => $indexes[$t->id] ?? [],
      ];
    }
    $pairs = [];
    $fk_columns = $select('divengine_db_fk_column');
    usort($fk_columns, fn ($a, $b) => $a->position <=> $b->position ?: $a->id <=> $b->id);
    foreach ($fk_columns as $m) {
      $pairs[$m->fk_id][] = [$column_by_id[$m->child_column_id]->name ?? NULL, $column_by_id[$m->parent_column_id]->name ?? NULL];
    }
    $relations = [];
    foreach ($select('divengine_db_fk') as $fk) {
      $child = $tables[$fk->child_table_id] ?? NULL;
      $parent = $tables[$fk->parent_table_id] ?? NULL;
      if ($child && $parent) {
        $relations[] = [
          'id' => (int) $fk->id,
          'name' => $fk->name,
          'implied' => (bool) $fk->implied,
          'child' => ['schema' => $child['schema'], 'table' => $child['name'], 'id' => $child['id']],
          'parent' => ['schema' => $parent['schema'], 'table' => $parent['name']],
          'pairs' => $pairs[$fk->id] ?? [],
          'on_delete_cascade' => (bool) $fk->on_delete_cascade,
          'on_update' => $fk->on_update,
        ];
      }
    }
    $routines = array_map(fn ($r) => [
      'id' => (int) $r->id,
      'schema' => $r->catalog ?: $r->schema_name ?: '',
      'name' => $r->name,
      'type' => $r->routine_type,
      'return_type' => $r->return_type,
      'parameters' => $r->parameter_signature,
      'definition' => $r->definition,
      'deterministic' => (bool) $r->deterministic,
      'sql_data_access' => $r->sql_data_access,
      'security_type' => $r->security_type,
      'comment' => $r->comment,
    ], $select('divengine_db_routine'));
    return ['snapshot' => $this->snapshotMeta($snapshot), 'tables' => array_values($tables), 'relations' => $relations, 'routines' => $routines];
  }

}
