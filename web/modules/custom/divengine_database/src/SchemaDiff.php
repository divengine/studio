<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

/**
 * Semantic, directional diff of two snapshots: what changed FROM A TO B.
 *
 * Pure and deterministic (no Drupal): it compares metadata, never row data
 * and never DDL text. ADDED is only in B, REMOVED only in A, MODIFIED in both
 * with different properties. Identity is semantic (keys built from names and
 * definitions), never record ids; renames are not inferred (a renamed
 * object is removed + added). Properties that only an enriched capture has
 * (charset, collation, ENUM values, AUTO_INCREMENT, view SQL, ON UPDATE) are
 * compared only when both snapshots have them.
 *
 * Input, per snapshot (see SchemaFacts::diffInput()):
 * snapshot{…}, tables[{id, schema, name, type, comment, view_definition,
 * columns[…], indexes[{id, name, unique, columns[{name, asc}]}]}],
 * relations[{id, name, implied, child{schema, table}, parent{schema, table},
 * pairs[[child, parent]], on_delete_cascade, on_update}], routines[…].
 */
final class SchemaDiff {

  const VERSION = 1;

  const TYPES = ['table', 'column', 'primary_key', 'index', 'foreign_key', 'implied_relation', 'routine'];

  const CHANGES = ['added', 'removed', 'modified', 'unchanged'];

  /**
   * Properties that make an object MODIFIED, per type.
   */
  const COMPARED = [
    'table' => ['kind', 'comment', 'view_definition'],
    'column' => ['type', 'nullable', 'default', 'auto_increment', 'auto_updated', 'charset', 'collation', 'enum_values', 'comment'],
    'primary_key' => ['columns'],
    'index' => ['name', 'unique'],
    'foreign_key' => ['name', 'on_delete_cascade', 'on_update'],
    'implied_relation' => ['on_delete_cascade'],
    'routine' => ['return_type', 'parameters', 'definition', 'deterministic', 'sql_data_access', 'security_type', 'comment'],
  ];

  const SKIP_WHEN_NOT_ENRICHED = [
    'table' => ['view_definition'],
    'column' => ['charset', 'collation', 'enum_values', 'auto_increment'],
    'foreign_key' => ['on_update'],
  ];

  /**
   * The diff FROM $a TO $b.
   *
   * @param bool|null $qualify_schema
   *   NULL: qualify table identity with the schema only when either snapshot
   *   spans several schemas.
   */
  public static function diff(array $a, array $b, bool $include_unchanged = FALSE, ?bool $qualify_schema = NULL): array {
    $start = microtime(TRUE);
    if ($qualify_schema === NULL) {
      $qualify_schema = FALSE;
      foreach ([$a, $b] as $snapshot) {
        if (count(array_unique(array_map(fn ($t) => (string) ($t['schema'] ?? ''), $snapshot['tables'] ?? []))) > 1) {
          $qualify_schema = TRUE;
        }
      }
    }
    $enriched = ['from' => self::enriched($a), 'to' => self::enriched($b)];
    $both = $enriched['from'] && $enriched['to'];
    $flat_a = self::flatten($a, $qualify_schema);
    $flat_b = self::flatten($b, $qualify_schema);
    $moved = self::moves($flat_a, $flat_b);
    $keys = array_keys($flat_a + $flat_b);

    $objects = [];
    $summary = array_fill_keys(self::TYPES, array_fill_keys(self::CHANGES, 0));
    foreach ($keys as $key) {
      $oa = $flat_a[$key] ?? NULL;
      $ob = $flat_b[$key] ?? NULL;
      $o = $ob ?? $oa;
      $changes = [];
      if ($oa && !$ob) {
        $change = 'removed';
      }
      elseif ($ob && !$oa) {
        $change = 'added';
      }
      else {
        $skip = $both ? [] : (self::SKIP_WHEN_NOT_ENRICHED[$oa['type']] ?? []);
        foreach (self::COMPARED[$oa['type']] as $property) {
          $before = $oa['props'][$property] ?? NULL;
          $after = $ob['props'][$property] ?? NULL;
          if (!in_array($property, $skip, TRUE) && $before !== $after) {
            $changes[] = ['property' => $property, 'before' => $before, 'after' => $after];
          }
        }
        if (isset($moved[$key])) {
          $changes[] = ['property' => 'position', 'before' => $oa['props']['_position'], 'after' => $ob['props']['_position']];
        }
        $change = $changes ? 'modified' : 'unchanged';
      }
      $summary[$o['type']][$change]++;
      if ($change === 'unchanged' && !$include_unchanged) {
        continue;
      }
      $before = $oa ? self::visible($oa['props']) : NULL;
      $after = $ob ? self::visible($ob['props']) : NULL;
      $objects[] = [
        'type' => $o['type'],
        'key' => $key,
        'change' => $change,
        'name' => $o['name'],
        'table' => $o['table'],
        'target_table' => $o['target_table'] ?? NULL,
        'parent' => $o['parent'],
        'before' => $before,
        'after' => $after,
        'changes' => $changes,
        'severity' => self::severity($o['type'], $change, $changes, $before, $after, $o['parent'] !== NULL && !isset($flat_a[$o['parent']])),
        'ids' => ['from' => $oa['ids'] ?? NULL, 'to' => $ob['ids'] ?? NULL],
      ];
    }
    $order = array_flip(self::TYPES);
    usort($objects, fn ($x, $y) => [$x['table'] === NULL, 0] <=> [$y['table'] === NULL, 0]
      ?: strcmp((string) $x['table'], (string) $y['table'])
      ?: $order[$x['type']] <=> $order[$y['type']]
      ?: strcmp($x['key'], $y['key']));

    $compared = count($keys);
    $changed = 0;
    foreach ($summary as $counts) {
      $changed += $counts['added'] + $counts['removed'] + $counts['modified'];
    }
    $severity = [];
    foreach ($objects as $object) {
      if ($object['severity']) {
        $severity[$object['severity']] = ($severity[$object['severity']] ?? 0) + 1;
      }
    }
    $notes = [];
    if (!$both) {
      $notes[] = 'One snapshot was captured without the enrichment: charset, collation, ENUM values, AUTO_INCREMENT, view SQL and ON UPDATE are not compared.';
    }
    return [
      'version' => self::VERSION,
      'from' => ($a['snapshot'] ?? []) + ['enriched' => $enriched['from']],
      'to' => ($b['snapshot'] ?? []) + ['enriched' => $enriched['to']],
      'identity' => ['qualify_schema' => $qualify_schema],
      'summary' => [
        'by_type' => $summary,
        'compared' => $compared,
        'changed' => $changed,
        'unchanged' => $compared - $changed,
        'by_severity' => (object) $severity,
      ],
      'notes' => $notes,
      'not_compared' => ['sequences', 'triggers', 'check constraints'],
      'objects' => $objects,
      'stats' => ['from_objects' => count($flat_a), 'to_objects' => count($flat_b), 'ms' => round((microtime(TRUE) - $start) * 1000, 2)],
    ];
  }

  /**
   * The union A ∪ B in the explorer's index shape, annotated with changes:
   * removed objects come from A (ghosts), everything else from B.
   */
  public static function unionGraph(array $a, array $b, array $diff): array {
    [$q, $changed, $tables, $column_id] = self::union($a, $b, $diff);
    $child_changes = [];
    foreach ($diff['objects'] as $o) {
      if ($o['change'] !== 'unchanged' && $o['table'] !== NULL && $o['type'] !== 'table') {
        $child_changes[$o['table']] ??= ['added' => 0, 'removed' => 0, 'modified' => 0];
        $child_changes[$o['table']][$o['change']]++;
      }
    }
    $real_a = self::fkSets($a, $q)[0];
    $real_b = self::fkSets($b, $q)[0];
    $out_tables = [];
    foreach ($tables as $key => $u) {
      $raw = $u['b'] ?? $u['a'];
      $own = $changed['table:' . $key] ?? NULL;
      $change = $own && in_array($own['change'], ['added', 'removed'], TRUE) ? $own['change'] : ($own || !empty($child_changes[$key]) ? 'modified' : 'unchanged');
      $names = [];
      foreach (['a', 'b'] as $side) {
        foreach ($u[$side]['columns'] ?? [] as $c) {
          $names[mb_strtolower($c['name'])] = TRUE;
        }
      }
      $out_tables[] = [
        'id' => $u['id'],
        'key' => $key,
        'name' => $raw['name'],
        'schema' => (string) ($raw['schema'] ?? ''),
        'type' => $raw['type'] ?? 'table',
        'columns' => count($names),
        'pk' => count(array_filter($raw['columns'] ?? [], fn ($c) => !empty($c['pk_position']))),
        'fk' => count(($u['b'] ? $real_b : $real_a)[$key] ?? []),
        'change' => $change,
        'origin' => $u['b'] ? 'to' : 'from',
        'table_change' => $own['change'] ?? 'unchanged',
        'child_changes' => $child_changes[$key] ?? ['added' => 0, 'removed' => 0, 'modified' => 0],
      ];
    }
    $relations = [];
    // B is written last: it wins for objects in both.
    foreach (['from' => $a, 'to' => $b] as $side => $snapshot) {
      foreach ($snapshot['relations'] ?? [] as $r) {
        $ck = self::tableKey($r['child'], $q);
        $pk = self::tableKey($r['parent'], $q);
        if (!isset($tables[$ck], $tables[$pk])) {
          continue;
        }
        $type = !empty($r['implied']) ? 'implied_relation' : 'foreign_key';
        $child_columns = array_map(fn ($p) => mb_strtolower((string) $p[0]), $r['pairs'] ?? []);
        $parent_columns = array_map(fn ($p) => mb_strtolower((string) $p[1]), $r['pairs'] ?? []);
        $key = sprintf('%s:%s(%s)->%s(%s)', $type, $ck, implode(',', $child_columns), $pk, implode(',', $parent_columns));
        $nullable = [];
        foreach (['a', 'b'] as $s) {
          foreach ($tables[$ck][$s]['columns'] ?? [] as $c) {
            $nullable[mb_strtolower($c['name'])] = !empty($c['nullable']);
          }
        }
        $columns = [];
        foreach ($r['pairs'] ?? [] as $i => $pair) {
          $columns[] = ['source' => $column_id[$ck . "\0" . $child_columns[$i]] ?? NULL, 'source_name' => $pair[0], 'target' => $column_id[$pk . "\0" . $parent_columns[$i]] ?? NULL, 'target_name' => $pair[1]];
        }
        $relations[$key] = [
          'id' => $r['id'],
          'key' => $key,
          'name' => (string) ($r['name'] ?? ''),
          'implied' => !empty($r['implied']),
          'on_delete_cascade' => !empty($r['on_delete_cascade']),
          'on_update' => $r['on_update'] ?? NULL,
          'source' => $tables[$ck]['id'],
          'target' => $tables[$pk]['id'],
          'columns' => $columns,
          'cardinality' => NULL,
          'optional' => (bool) array_filter($child_columns, fn ($c) => $nullable[$c] ?? FALSE),
          'change' => $changed[$key]['change'] ?? 'unchanged',
          'origin' => $side,
        ];
      }
    }
    ksort($relations, SORT_STRING);
    $relations = array_values($relations);
    $real = count(array_filter($relations, fn ($r) => !$r['implied']));
    return [
      'snapshot' => [
        'id' => $diff['to']['id'] ?? NULL,
        'key' => $diff['to']['key'] ?? NULL,
        'database_code' => $diff['to']['database_code'] ?? NULL,
        'catalog' => $diff['to']['catalog'] ?? NULL,
        'diff' => ['from' => $diff['from'], 'to' => $diff['to'], 'summary' => $diff['summary'], 'notes' => $diff['notes']],
      ],
      'tables' => $out_tables,
      'relations' => $relations,
      'stats' => [
        'tables' => count($out_tables),
        'views' => count(array_filter($out_tables, fn ($t) => $t['type'] === 'view')),
        'columns' => array_sum(array_column($out_tables, 'columns')),
        'relations' => count($relations),
        'real' => $real,
        'implied' => count($relations) - $real,
        'changed_tables' => count(array_filter($out_tables, fn ($t) => $t['change'] !== 'unchanged')),
      ],
    ];
  }

  /**
   * Column rows of union tables: those of B plus the removed ones of A
   * (ghosts, after their nearest surviving predecessor), with their changes,
   * and the other changes of each table (keys, indexes, relations).
   */
  public static function unionDetails(array $a, array $b, array $diff, array $table_ids): array {
    [$q, $changed, $tables, $column_id] = self::union($a, $b, $diff);
    $by_id = [];
    foreach ($tables as $key => $u) {
      $by_id[$u['id']] = $key;
    }
    $fk = ['a' => self::fkSets($a, $q), 'b' => self::fkSets($b, $q)];
    $out = [];
    foreach ($table_ids as $tid) {
      $key = $by_id[$tid] ?? NULL;
      if ($key === NULL) {
        continue;
      }
      $u = $tables[$key];
      $row = function (array $c, string $side) use ($u, $key, $changed, $column_id, $fk): array {
        $name = mb_strtolower($c['name']);
        [$unique, $part] = self::uniqueColumns($u[$side]);
        $object = $changed["column:$key.$name"] ?? NULL;
        $real = isset($fk[$side][0][$key][$name]);
        return [
          'id' => $column_id[$key . "\0" . $name],
          'name' => $c['name'],
          'position' => $c['position'] ?? NULL,
          'type' => mb_strtolower(self::typeLabel($c)),
          'data_type' => $c['data_type'] ?? NULL,
          'nullable' => !empty($c['nullable']),
          'default' => !empty($c['has_default']) ? ($c['default'] ?? NULL) : NULL,
          'has_default' => !empty($c['has_default']),
          'pk' => (int) ($c['pk_position'] ?? 0),
          'fk' => $real,
          'implied_fk' => isset($fk[$side][1][$key][$name]) && !$real,
          'unique' => isset($unique[$name]) ? 'unique' : (isset($part[$name]) ? 'part' : FALSE),
          'auto_increment' => !empty($c['auto_increment']),
          'enum' => $c['enum_values'] ?? NULL,
          'comment' => $c['comment'] ?? NULL,
          'change' => $object['change'] ?? 'unchanged',
          'changes' => $object['changes'] ?? [],
        ];
      };
      $by_position = fn (array $columns) => self::sorted($columns, fn ($x, $y) => ($x['position'] ?? 0) <=> ($y['position'] ?? 0));
      $columns_b = $by_position($u['b']['columns'] ?? []);
      $columns_a = $by_position($u['a']['columns'] ?? []);
      if (!$u['b']) {
        $rows = array_map(fn ($c) => $row($c, 'a'), $columns_a);
      }
      else {
        $rows = array_map(fn ($c) => $row($c, 'b'), $columns_b);
        $names_b = array_flip(array_map(fn ($c) => mb_strtolower($c['name']), $columns_b));
        $previous = NULL;
        foreach ($columns_a as $c) {
          $name = mb_strtolower($c['name']);
          if (isset($names_b[$name])) {
            $previous = $name;
            continue;
          }
          $at = 0;
          if ($previous !== NULL) {
            foreach ($rows as $i => $r) {
              if (mb_strtolower($r['name']) === $previous) {
                $at = $i + 1;
                break;
              }
            }
          }
          while ($at < count($rows) && $rows[$at]['change'] === 'removed') {
            $at++;
          }
          array_splice($rows, $at, 0, [$row($c, 'a')]);
          $previous = $name;
        }
      }
      $raw = $u['b'] ?? $u['a'];
      $out[$tid] = [
        'type' => $raw['type'] ?? 'table',
        'comment' => $raw['comment'] ?? NULL,
        'view_definition' => $raw['view_definition'] ?? NULL,
        'columns' => $rows,
        'change' => $changed['table:' . $key]['change'] ?? 'unchanged',
        'object_changes' => array_values(array_filter($diff['objects'], fn ($o) => $o['table'] === $key && $o['type'] !== 'column' && $o['change'] !== 'unchanged')),
      ];
    }
    return ['tables' => (object) $out];
  }

  /**
   * A type for people: VARCHAR(36), DECIMAL(10,2), INT.
   */
  public static function typeLabel(array $column): string {
    $type = mb_strtoupper((string) ($column['data_type'] ?? '') ?: '?');
    $size = $column['size'] ?? NULL;
    if ($size && in_array($type, ['VARCHAR', 'CHAR', 'VARBINARY', 'BINARY', 'CHARACTER VARYING', 'CHARACTER', 'BPCHAR'], TRUE)) {
      return "$type($size)";
    }
    if ($size && in_array($type, ['DECIMAL', 'NUMERIC'], TRUE)) {
      return sprintf('%s(%s,%s)', $type, $size, ($column['scale'] ?? 0) ?: 0);
    }
    return $type;
  }

  private static function sorted(array $items, callable $compare): array {
    usort($items, $compare);
    return $items;
  }

  /**
   * Whitespace is formatting, not schema.
   */
  private static function ws(mixed $text): ?string {
    return $text === NULL ? NULL : trim(preg_replace('/\s+/u', ' ', (string) $text));
  }

  private static function opt(mixed $value): mixed {
    return $value === '' || $value === NULL ? NULL : $value;
  }

  /**
   * Charset and collation: the literal "NULL" also means none.
   */
  private static function optName(mixed $value): mixed {
    $value = self::opt($value);
    return is_string($value) && strtoupper($value) === 'NULL' ? NULL : $value;
  }

  private static function enriched(array $snapshot): bool {
    foreach ($snapshot['tables'] ?? [] as $table) {
      foreach ($table['columns'] ?? [] as $column) {
        if (self::optName($column['charset'] ?? NULL) || self::optName($column['collation'] ?? NULL)) {
          return TRUE;
        }
      }
    }
    return !empty($snapshot['routines']);
  }

  private static function tableKey(array $ref, bool $qualify): string {
    $name = $ref['table'] ?? $ref['name'];
    return $qualify ? ($ref['schema'] ?? '') . '.' . $name : $name;
  }

  private static function visible(array $props): array {
    return array_filter($props, fn ($key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
  }

  /**
   * Every compared object of a snapshot, by canonical key.
   */
  private static function flatten(array $snapshot, bool $qualify): array {
    $out = [];
    $put = function (array $object) use (&$out): void {
      $key = $object['key'];
      for ($n = 2; isset($out[$key]); $n++) {
        $key = $object['key'] . '#' . $n;
      }
      $object['key'] = $key;
      $out[$key] = $object;
    };
    $tables = self::sorted($snapshot['tables'] ?? [], fn ($x, $y) => strcmp((string) ($x['schema'] ?? ''), (string) ($y['schema'] ?? '')) ?: strcmp($x['name'], $y['name']));
    foreach ($tables as $t) {
      $tk = self::tableKey($t, $qualify);
      $columns = self::sorted($t['columns'] ?? [], fn ($x, $y) => ($x['position'] ?? 0) <=> ($y['position'] ?? 0) ?: strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
      $put([
        'type' => 'table', 'key' => "table:$tk", 'parent' => NULL, 'name' => $tk, 'table' => $tk,
        'ids' => ['table' => $t['id'] ?? NULL],
        'props' => [
          'kind' => ($t['type'] ?? '') ?: 'table',
          'schema' => (string) ($t['schema'] ?? ''),
          'comment' => self::opt($t['comment'] ?? NULL),
          'view_definition' => self::ws(self::opt($t['view_definition'] ?? NULL)),
        ],
      ]);
      foreach ($columns as $c) {
        $put([
          'type' => 'column', 'key' => sprintf('column:%s.%s', $tk, mb_strtolower($c['name'])), 'parent' => "table:$tk",
          'name' => $c['name'], 'table' => $tk, 'ids' => ['table' => $t['id'] ?? NULL, 'column' => $c['id'] ?? NULL],
          'props' => [
            'data_type' => mb_strtoupper((string) ($c['data_type'] ?? '')) ?: NULL,
            'size' => ($c['size'] ?? NULL) ?: NULL,
            'scale' => ($c['scale'] ?? NULL) ?: NULL,
            'type' => self::typeLabel($c),
            'nullable' => !empty($c['nullable']),
            // "No default" differs from "DEFAULT NULL".
            'default' => !empty($c['has_default']) ? ['has' => TRUE, 'value' => $c['default'] ?? NULL] : ['has' => FALSE, 'value' => NULL],
            'auto_increment' => !empty($c['auto_increment']),
            'auto_updated' => !empty($c['auto_updated']),
            'charset' => self::optName($c['charset'] ?? NULL),
            'collation' => self::optName($c['collation'] ?? NULL),
            'enum_values' => self::opt($c['enum_values'] ?? NULL),
            'comment' => self::opt($c['comment'] ?? NULL),
            '_position' => ($c['position'] ?? 0) ?: 0,
          ],
        ]);
      }
      $pk = self::sorted(array_filter($columns, fn ($c) => !empty($c['pk_position'])), fn ($x, $y) => $x['pk_position'] <=> $y['pk_position']);
      if ($pk) {
        $names = array_map(fn ($c) => $c['name'], $pk);
        $put([
          'type' => 'primary_key', 'key' => "primary_key:$tk", 'parent' => "table:$tk", 'name' => 'PRIMARY KEY', 'table' => $tk,
          'ids' => ['table' => $t['id'] ?? NULL],
          'props' => ['columns' => array_map('mb_strtolower', $names), 'display_columns' => $names],
        ]);
      }
      foreach (self::sorted($t['indexes'] ?? [], fn ($x, $y) => strcmp($x['name'], $y['name'])) as $ix) {
        // The PRIMARY index is the primary_key object.
        if (strtoupper($ix['name']) === 'PRIMARY') {
          continue;
        }
        $definition = array_map(fn ($m) => mb_strtolower($m['name']) . (($m['asc'] ?? TRUE) ? '' : ' desc'), $ix['columns'] ?? []);
        $put([
          'type' => 'index', 'key' => sprintf('index:%s(%s)', $tk, implode(',', $definition)), 'parent' => "table:$tk",
          'name' => $ix['name'], 'table' => $tk, 'ids' => ['table' => $t['id'] ?? NULL, 'index' => $ix['id'] ?? NULL],
          'props' => ['name' => $ix['name'], 'unique' => !empty($ix['unique']), 'columns' => $definition],
        ]);
      }
    }
    $relations = self::sorted($snapshot['relations'] ?? [], fn ($x, $y) => strcmp($x['child']['table'], $y['child']['table']) ?: strcmp($x['parent']['table'], $y['parent']['table']) ?: strcmp((string) ($x['name'] ?? ''), (string) ($y['name'] ?? '')));
    foreach ($relations as $r) {
      $ck = self::tableKey($r['child'], $qualify);
      $pk = self::tableKey($r['parent'], $qualify);
      $child_columns = array_map(fn ($p) => mb_strtolower((string) $p[0]), $r['pairs'] ?? []);
      $parent_columns = array_map(fn ($p) => mb_strtolower((string) $p[1]), $r['pairs'] ?? []);
      $type = !empty($r['implied']) ? 'implied_relation' : 'foreign_key';
      $put([
        'type' => $type, 'key' => sprintf('%s:%s(%s)->%s(%s)', $type, $ck, implode(',', $child_columns), $pk, implode(',', $parent_columns)),
        'parent' => "table:$ck", 'name' => (string) ($r['name'] ?? ''), 'table' => $ck, 'target_table' => $pk,
        'ids' => ['relation' => $r['id'] ?? NULL, 'table' => $r['child']['id'] ?? NULL],
        'props' => [
          'name' => (string) ($r['name'] ?? ''),
          'on_delete_cascade' => !empty($r['on_delete_cascade']),
          'on_update' => self::opt($r['on_update'] ?? NULL),
          'child' => $ck, 'parent' => $pk, 'child_columns' => $child_columns, 'parent_columns' => $parent_columns,
        ],
      ]);
    }
    $routines = self::sorted($snapshot['routines'] ?? [], fn ($x, $y) => strcmp((string) ($x['type'] ?? ''), (string) ($y['type'] ?? '')) ?: strcmp($x['name'], $y['name']));
    foreach ($routines as $rt) {
      $rk = $qualify ? ($rt['schema'] ?? '') . '.' . $rt['name'] : $rt['name'];
      $put([
        'type' => 'routine', 'key' => sprintf('routine:%s:%s', $rt['type'] ?? '', $rk), 'parent' => NULL, 'name' => $rk, 'table' => NULL,
        'ids' => ['routine' => $rt['id'] ?? NULL],
        'props' => [
          'routine_type' => $rt['type'] ?? NULL,
          'return_type' => self::opt($rt['return_type'] ?? NULL),
          'parameters' => self::ws(self::opt($rt['parameters'] ?? NULL)),
          'definition' => self::ws(self::opt($rt['definition'] ?? NULL)),
          'deterministic' => !empty($rt['deterministic']),
          'sql_data_access' => self::opt($rt['sql_data_access'] ?? NULL),
          'security_type' => self::opt($rt['security_type'] ?? NULL),
          'comment' => self::opt($rt['comment'] ?? NULL),
        ],
      ]);
    }
    return $out;
  }

  /**
   * Columns whose rank among the columns present in both snapshots changed:
   * adding or removing a column is not a reorder.
   */
  private static function moves(array $a, array $b): array {
    $by_table = [];
    foreach ($a as $key => $o) {
      if ($o['type'] === 'column' && isset($b[$key])) {
        $by_table[$o['table']][] = $key;
      }
    }
    $moved = [];
    foreach ($by_table as $keys) {
      $rank_a = self::sorted($keys, fn ($x, $y) => $a[$x]['props']['_position'] <=> $a[$y]['props']['_position']);
      $rank_b = self::sorted($keys, fn ($x, $y) => $b[$x]['props']['_position'] <=> $b[$y]['props']['_position']);
      if ($rank_a !== $rank_b) {
        $ia = array_flip($rank_a);
        $ib = array_flip($rank_b);
        foreach ($keys as $key) {
          if ($ia[$key] !== $ib[$key]) {
            $moved[$key] = TRUE;
          }
        }
      }
    }
    return $moved;
  }

  /**
   * A technical class, not business impact: destructive (removed table,
   * column, routine), compatibility (type, nullability, default…),
   * structural (keys, indexes, relations), additive (new objects), cosmetic
   * (comments, order).
   */
  private static function severity(string $type, string $change, array $changes, ?array $before, ?array $after, bool $parent_is_new): ?string {
    if ($change === 'added') {
      if (in_array($type, ['table', 'column', 'routine'], TRUE)) {
        // A NOT NULL column without default on an existing table.
        if ($type === 'column' && !$parent_is_new && $after && empty($after['nullable']) && empty($after['default']['has'])) {
          return 'compatibility';
        }
        return 'additive';
      }
      // A new unique index may reject existing rows.
      if ($type === 'index' && !$parent_is_new && $after && !empty($after['unique'])) {
        return 'compatibility';
      }
      return 'structural';
    }
    if ($change === 'removed') {
      return in_array($type, ['table', 'column', 'routine'], TRUE) ? 'destructive' : 'structural';
    }
    if ($change !== 'modified') {
      return NULL;
    }
    $names = array_column($changes, 'property');
    if ($type === 'column' && array_intersect($names, ['type', 'nullable', 'default', 'auto_increment', 'auto_updated', 'charset', 'collation', 'enum_values'])) {
      return 'compatibility';
    }
    if (in_array($type, ['primary_key', 'foreign_key', 'implied_relation'], TRUE) || ($type === 'index' && in_array('unique', $names, TRUE))) {
      return 'structural';
    }
    if ($type === 'routine' && array_diff($names, ['comment'])) {
      return 'compatibility';
    }
    if ($type === 'table' && in_array('kind', $names, TRUE)) {
      return 'structural';
    }
    return 'cosmetic';
  }

  private static function union(array $a, array $b, array $diff): array {
    $q = $diff['identity']['qualify_schema'];
    $changed = [];
    foreach ($diff['objects'] as $o) {
      $changed[$o['key']] = $o;
    }
    $raw = function (array $snapshot) use ($q): array {
      $out = [];
      foreach ($snapshot['tables'] ?? [] as $t) {
        $out[self::tableKey($t, $q)] = $t;
      }
      return $out;
    };
    $ta = $raw($a);
    $tb = $raw($b);
    $keys = array_keys($ta + $tb);
    sort($keys, SORT_STRING);
    $tables = [];
    $column_id = [];
    foreach ($keys as $key) {
      $tables[$key] = ['key' => $key, 'id' => ($tb[$key] ?? $ta[$key])['id'], 'a' => $ta[$key] ?? NULL, 'b' => $tb[$key] ?? NULL];
      // B is last: it wins.
      foreach (['a', 'b'] as $side) {
        foreach ($tables[$key][$side]['columns'] ?? [] as $c) {
          $column_id[$key . "\0" . mb_strtolower($c['name'])] = $c['id'];
        }
      }
    }
    return [$q, $changed, $tables, $column_id];
  }

  /**
   * Child columns of real and implied relationships, by table key.
   */
  private static function fkSets(array $snapshot, bool $q): array {
    $real = $implied = [];
    foreach ($snapshot['relations'] ?? [] as $r) {
      $ck = self::tableKey($r['child'], $q);
      foreach ($r['pairs'] ?? [] as $pair) {
        if (!empty($r['implied'])) {
          $implied[$ck][mb_strtolower((string) $pair[0])] = TRUE;
        }
        else {
          $real[$ck][mb_strtolower((string) $pair[0])] = TRUE;
        }
      }
    }
    return [$real, $implied];
  }

  private static function uniqueColumns(?array $table): array {
    $single = $part = [];
    foreach ($table['indexes'] ?? [] as $ix) {
      if (!empty($ix['unique'])) {
        $names = array_map(fn ($m) => mb_strtolower($m['name']), $ix['columns'] ?? []);
        foreach ($names as $name) {
          count($names) === 1 ? $single[$name] = TRUE : $part[$name] = TRUE;
        }
      }
    }
    return [$single, $part];
  }

}
