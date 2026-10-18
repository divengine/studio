<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\Core\Database\Connection;

/**
 * Deterministic rules over the facts of a snapshot.
 *
 * Findings are derived: recomputing them replaces the previous ones. Implied
 * relationships are facts of the analyzer, not findings, and they are
 * ignored by the rules that judge relationships.
 */
class FindingsAnalyzer {

  const PRODUCER = 'divengine_database';

  /**
   * Rule code => [severity, version, title].
   */
  const RULES = [
    'TABLE_NO_INDEX' => ['warning', '1', 'Table has no indexes'],
    'TABLE_SINGLE_COLUMN' => ['info', '1', 'Table has a single column'],
    'TABLE_ORPHAN' => ['info', '1', 'Table has no real foreign-key relationship'],
    'COLUMN_DEFAULT_NULL_STRING' => ['warning', '1', "Column default is the literal string 'NULL'"],
    'TABLE_INCREMENTING_COLUMNS' => ['info', '1', 'Table has incrementing column names (possible denormalization)'],
  ];

  public function __construct(protected Connection $database) {}

  /**
   * Recomputes the findings of a snapshot.
   *
   * @return array
   *   The finding counters of the snapshot.
   */
  public function analyze(int $sid): array {
    $tables = $this->database->select('divengine_db_table', 't')->fields('t', ['id', 'name', 'obj_type', 'logical_key'])->condition('sid', $sid)->execute()->fetchAllAssoc('id');
    $columns = [];
    foreach ($this->database->select('divengine_db_column', 'c')->fields('c', ['table_id', 'name'])->condition('sid', $sid)->orderBy('position')->execute() as $column) {
      $columns[$column->table_id][] = $column->name;
    }
    $indexed = $this->database->select('divengine_db_index', 'i')->fields('i', ['table_id'])->condition('sid', $sid)->distinct()->execute()->fetchCol();
    $indexed = array_flip($indexed);
    $related = [];
    foreach ($this->database->select('divengine_db_fk', 'f')->fields('f', ['child_table_id', 'parent_table_id'])->condition('sid', $sid)->condition('implied', 0)->execute() as $fk) {
      $related[$fk->child_table_id] = $related[$fk->parent_table_id] = TRUE;
    }

    $rows = [];
    $add = function (string $rule, string $type, string $path, string $detail, ?int $table = NULL, ?int $column = NULL) use (&$rows, $sid) {
      [$severity, $version, $title] = static::RULES[$rule];
      $rows[] = [
        'sid' => $sid,
        'rule_code' => $rule,
        'rule_version' => $version,
        'producer' => static::PRODUCER,
        'object_type' => $type,
        'table_id' => $table,
        'column_id' => $column,
        'severity' => $severity,
        'title' => $title,
        'detail' => $detail,
        'logical_key' => "finding:$rule:$path",
      ];
    };
    foreach ($tables as $id => $table) {
      $id = (int) $id;
      $names = $columns[$id] ?? [];
      if ($table->obj_type === 'table' && !isset($indexed[$id])) {
        $add('TABLE_NO_INDEX', 'table', $table->logical_key, sprintf("Table '%s' has no indexes.", $table->name), $id);
      }
      if (count($names) === 1) {
        $add('TABLE_SINGLE_COLUMN', 'table', $table->logical_key, sprintf("Table '%s' has a single column.", $table->name), $id);
      }
      if ($table->obj_type === 'table' && !isset($related[$id])) {
        $add('TABLE_ORPHAN', 'table', $table->logical_key, sprintf("Table '%s' has no real foreign key (implied relationships are ignored for this rule).", $table->name), $id);
      }
      foreach (static::incrementingGroups($names) as $base => $members) {
        $add('TABLE_INCREMENTING_COLUMNS', 'table', $table->logical_key . '#' . $base, sprintf("Table '%s' has incrementing column names %s (base '%s'): a possible denormalization, not proof of a defect.", $table->name, implode(', ', $members), $base), $id);
      }
    }
    $query = $this->database->select('divengine_db_column', 'c')->fields('c', ['id', 'table_id', 'name', 'default_value', 'logical_key'])->condition('c.sid', $sid)->condition('c.has_default', 1)->condition('c.default_value', ['NULL', 'null'], 'IN');
    $query->innerJoin('divengine_db_table', 't', 't.id = c.table_id');
    $query->addField('t', 'name', 'table_name');
    foreach ($query->execute() as $column) {
      $add('COLUMN_DEFAULT_NULL_STRING', 'column', $column->logical_key, sprintf("Column '%s.%s' default is the literal string '%s' (likely a mistake for no default).", $column->table_name, $column->name, $column->default_value), (int) $column->table_id, (int) $column->id);
    }

    $this->database->delete('divengine_db_finding')->condition('sid', $sid)->execute();
    foreach (array_chunk($rows, 500) as $chunk) {
      $insert = $this->database->insert('divengine_db_finding')->fields(array_keys($chunk[0]));
      foreach ($chunk as $row) {
        $insert->values($row);
      }
      $insert->execute();
    }
    $by = array_count_values(array_column($rows, 'severity'));
    return ['finding_count' => count($rows), 'finding_warning_count' => $by['warning'] ?? 0, 'finding_error_count' => $by['error'] ?? 0];
  }

  /**
   * Column names that share a base and differ by a trailing number
   * (address1/address2, phone_1/phone_2), by lowercase base.
   */
  public static function incrementingGroups(array $names, int $min_base = 2, int $min_members = 2): array {
    $groups = [];
    foreach ($names as $name) {
      if (!preg_match('/^(.*?)[_-]?(\d+)$/', (string) $name, $m) || mb_strlen($m[1]) < $min_base || ctype_digit($m[1])) {
        continue;
      }
      $groups[mb_strtolower($m[1])][] = [(int) $m[2], $name];
    }
    $out = [];
    ksort($groups, SORT_STRING);
    foreach ($groups as $base => $members) {
      if (count($members) >= $min_members && count(array_unique(array_column($members, 0))) >= $min_members) {
        usort($members, fn ($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
        $out[$base] = array_column($members, 1);
      }
    }
    return $out;
  }

}
