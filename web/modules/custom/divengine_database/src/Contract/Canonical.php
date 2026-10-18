<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Contract;

/**
 * The canonical content of a snapshot: the one definition of its hash.
 *
 * Every schema fact of the contract participates, in a fixed key set and a
 * pinned order, so the hash is independent of key order, list order and
 * whitespace in the payload. Optional enrichment (routines, view SQL,
 * charset, collation, ENUM values, AUTO_INCREMENT, ON UPDATE) takes part only
 * when present. It produces the same hash as the original CodeIntel
 * producer (canonical.py), so snapshots keep their identity across tools.
 */
final class Canonical {

  const CONTRACT_VERSION = '1.0';

  /**
   * Hex characters of the content hash used for a key without revision.
   */
  const KEY_HASH_PREFIX = 16;

  /**
   * The canonical, fully ordered projection of the facts of a payload.
   */
  public static function facts(array $payload): array {
    $tables = array_map([self::class, 'table'], self::items($payload['tables'] ?? []));
    usort($tables, fn ($a, $b) => strcmp((string) $a['logical_key'], (string) $b['logical_key']));
    $fks = array_map([self::class, 'foreignKey'], self::items($payload['foreign_keys'] ?? []));
    usort($fks, fn ($a, $b) => strcmp((string) $a['logical_key'], (string) $b['logical_key']));
    $out = ['contract_version' => self::CONTRACT_VERSION, 'tables' => $tables, 'foreign_keys' => $fks];
    $routines = self::items($payload['routines'] ?? []);
    if ($routines) {
      $routines = array_map([self::class, 'routine'], $routines);
      usort($routines, fn ($a, $b) => strcmp((string) $a['logical_key'], (string) $b['logical_key']));
      $out['routines'] = $routines;
    }
    return $out;
  }

  /**
   * The SHA-256 of the canonical content: equal if and only if the facts are.
   */
  public static function contentHash(array $payload): string {
    return hash('sha256', self::encode(self::facts($payload)));
  }

  /**
   * <identifier>@<revision>, or <identifier>@<hash prefix> without revision.
   */
  public static function snapshotKey(string $identifier, ?string $revision, string $hash): string {
    $revision = trim((string) $revision);
    return $identifier . '@' . ($revision !== '' ? $revision : substr($hash, 0, self::KEY_HASH_PREFIX));
  }

  /**
   * JSON with sorted keys and no whitespace, characters left unescaped.
   */
  public static function encode(array $value): string {
    return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
  }

  private static function sortKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      return array_map([self::class, 'sortKeys'], $value);
    }
    ksort($value, SORT_STRING);
    // An object stays an object even when it ends up empty.
    return (object) array_map([self::class, 'sortKeys'], $value);
  }

  private static function items(mixed $value): array {
    return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
  }

  /**
   * Truthiness as the contract producers understand it.
   */
  public static function truthy(mixed $value): bool {
    return is_string($value) ? $value !== '' : (bool) $value;
  }

  private static function empty(mixed $value): bool {
    return $value === NULL || $value === '' || $value === FALSE || $value === [] || $value === 0 || $value === 0.0;
  }

  private static function optional(array $from, array &$to, array $keys): void {
    foreach ($keys as $key) {
      if (array_key_exists($key, $from) && !self::empty($from[$key])) {
        $to[$key] = $from[$key];
      }
    }
  }

  private static function position(array $item, string $key = 'position'): int|float {
    $value = $item[$key] ?? NULL;
    return is_int($value) || is_float($value) ? $value : 0;
  }

  private static function column(array $c): array {
    $out = [
      'logical_key' => $c['logical_key'] ?? NULL,
      'name' => $c['name'] ?? NULL,
      'position' => $c['position'] ?? NULL,
      'data_type' => $c['data_type'] ?? NULL,
      'jdbc_type_code' => $c['jdbc_type_code'] ?? NULL,
      'size' => $c['size'] ?? NULL,
      'scale' => $c['scale'] ?? NULL,
      'nullable' => self::truthy($c['nullable'] ?? NULL),
      'has_default' => self::truthy($c['has_default'] ?? NULL),
      'default_value' => $c['default_value'] ?? NULL,
      'auto_updated' => self::truthy($c['auto_updated'] ?? NULL),
      'comment' => $c['comment'] ?? NULL,
      'pk_position' => self::empty($c['pk_position'] ?? NULL) ? 0 : $c['pk_position'],
    ];
    self::optional($c, $out, ['charset', 'collation', 'enum_values']);
    if (self::truthy($c['auto_increment'] ?? NULL)) {
      $out['auto_increment'] = TRUE;
    }
    return $out;
  }

  private static function index(array $ix): array {
    $columns = array_map(fn ($m) => [
      'column_logical_key' => $m['column_logical_key'] ?? NULL,
      'position' => $m['position'] ?? NULL,
      'ascending' => array_key_exists('ascending', $m) && $m['ascending'] !== NULL ? self::truthy($m['ascending']) : TRUE,
    ], self::items($ix['columns'] ?? []));
    usort($columns, fn ($a, $b) => [self::position($a), 0] <=> [self::position($b), 0] ?: strcmp((string) $a['column_logical_key'], (string) $b['column_logical_key']));
    return [
      'logical_key' => $ix['logical_key'] ?? NULL,
      'name' => $ix['name'] ?? NULL,
      'is_unique' => self::truthy($ix['is_unique'] ?? NULL),
      'columns' => $columns,
    ];
  }

  private static function table(array $t): array {
    $columns = array_map([self::class, 'column'], self::items($t['columns'] ?? []));
    usort($columns, fn ($a, $b) => self::position($a) <=> self::position($b) ?: strcmp((string) $a['logical_key'], (string) $b['logical_key']));
    $indexes = array_map([self::class, 'index'], self::items($t['indexes'] ?? []));
    usort($indexes, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['logical_key'], (string) $b['logical_key']));
    $out = [
      'logical_key' => $t['logical_key'] ?? NULL,
      'name' => $t['name'] ?? NULL,
      'catalog' => $t['catalog'] ?? NULL,
      'obj_type' => array_key_exists('obj_type', $t) ? $t['obj_type'] : 'table',
      'comment' => $t['comment'] ?? NULL,
      'columns' => $columns,
      'indexes' => $indexes,
    ];
    self::optional($t, $out, ['view_definition']);
    return $out;
  }

  private static function foreignKey(array $fk): array {
    $columns = array_map(fn ($m) => [
      'position' => $m['position'] ?? NULL,
      'child_column_key' => $m['child_column_key'] ?? NULL,
      'parent_column_key' => $m['parent_column_key'] ?? NULL,
    ], self::items($fk['columns'] ?? []));
    usort($columns, fn ($a, $b) => self::position($a) <=> self::position($b)
      ?: strcmp((string) $a['child_column_key'], (string) $b['child_column_key'])
      ?: strcmp((string) $a['parent_column_key'], (string) $b['parent_column_key']));
    $out = [
      'logical_key' => $fk['logical_key'] ?? NULL,
      'name' => $fk['name'] ?? NULL,
      'implied' => self::truthy($fk['implied'] ?? NULL),
      'on_delete_cascade' => self::truthy($fk['on_delete_cascade'] ?? NULL),
      'child_table_key' => $fk['child_table_key'] ?? NULL,
      'parent_table_key' => $fk['parent_table_key'] ?? NULL,
      'columns' => $columns,
    ];
    self::optional($fk, $out, ['on_update']);
    return $out;
  }

  private static function routine(array $r): array {
    return [
      'logical_key' => $r['logical_key'] ?? NULL,
      'name' => $r['name'] ?? NULL,
      'routine_type' => $r['routine_type'] ?? NULL,
      'return_type' => $r['return_type'] ?? NULL,
      'parameter_signature' => $r['parameter_signature'] ?? NULL,
      'definition' => $r['definition'] ?? NULL,
      'deterministic' => self::truthy($r['deterministic'] ?? NULL),
      'sql_data_access' => $r['sql_data_access'] ?? NULL,
      'security_type' => $r['security_type'] ?? NULL,
    ];
  }

}
