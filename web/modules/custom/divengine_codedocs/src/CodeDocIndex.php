<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs;

use Drupal\Core\Database\Connection;
use Drupal\divengine_codedocs\Contract\Canonical;
use Drupal\divengine_codedocs\Contract\ContractException;

/** Snapshot facts. Public callers must check snapshot access before reading. */
class CodeDocIndex {

  const TABLES = ['endpoint', 'ref', 'tag', 'param', 'symbol', 'unit', 'batch'];

  public function __construct(protected Connection $database) {}

  public function forget(array $ids): void {
    if ($ids) {
      foreach (static::TABLES as $table) {
        $this->database->delete('divengine_codedoc_' . $table)->condition('sid', $ids, 'IN')->execute();
      }
    }
  }

  public function rows(string $table, int $sid, array $conditions = [], int $limit = 200, int $offset = 0): array {
    if (!in_array($table, static::TABLES, TRUE)) {
      throw new \InvalidArgumentException('Unknown fact table.');
    }
    $query = $this->database->select('divengine_codedoc_' . $table, 'f')->fields('f')->condition('sid', $sid);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return $query->orderBy('id')->range(max(0, $offset), max(1, min(500, $limit)))->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function search(int $sid, string $text, int $offset = 0): array {
    $query = $this->database->select('divengine_codedoc_symbol', 's')->fields('s')->condition('sid', $sid);
    if ($text !== '') {
      $like = '%' . $this->database->escapeLike($text) . '%';
      $query->condition($query->orConditionGroup()->condition('name', $like, 'LIKE')->condition('qualified_name', $like, 'LIKE')->condition('symbol_key', $like, 'LIKE'));
    }
    return $query->orderBy('symbol_key')->range(max(0, $offset), 100)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Every unit of a snapshot with its top-level symbols and coverage.
   */
  public function units(int $sid): array {
    $units = $this->database->select('divengine_codedoc_unit', 'u')->fields('u', ['id', 'unit_key', 'kind', 'name', 'path', 'parent_key', 'category', 'is_test', 'doc_summary'])->condition('sid', $sid)->orderBy('name')->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    $counts = $this->database->query("SELECT unit_id, SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END) AS symbols, COUNT(*) AS total FROM {divengine_codedoc_symbol} WHERE sid = :sid GROUP BY unit_id", [':sid' => $sid])->fetchAllAssoc('unit_id', \PDO::FETCH_ASSOC);
    $coverage = $this->database->query("SELECT unit_id, COUNT(*) AS documentable, SUM(documented) AS documented FROM {divengine_codedoc_symbol} WHERE sid = :sid " . $this->eligible() . " GROUP BY unit_id", [':sid' => $sid, ':sid2' => $sid])->fetchAllAssoc('unit_id', \PDO::FETCH_ASSOC);
    foreach ($units as $id => &$unit) {
      $unit['symbols'] = (int) ($counts[$id]['symbols'] ?? 0);
      $unit['documentable'] = (int) ($coverage[$id]['documentable'] ?? 0);
      $unit['documented'] = (int) ($coverage[$id]['documented'] ?? 0);
    }
    return $units;
  }

  /**
   * The symbols of a unit, members included, in source order.
   */
  public function unitSymbols(int $sid, int $unit): array {
    return $this->database->select('divengine_codedoc_symbol', 's')->fields('s')->condition('sid', $sid)->condition('unit_id', $unit)->orderBy('line')->orderBy('id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Ids of symbols by key.
   */
  public function symbolIds(int $sid, array $keys): array {
    $out = [];
    foreach (array_chunk($keys, 500) as $chunk) {
      $out += $this->database->select('divengine_codedoc_symbol', 's')->fields('s', ['symbol_key', 'id'])->condition('sid', $sid)->condition('symbol_key', $chunk, 'IN')->execute()->fetchAllKeyed();
    }
    return $out;
  }

  /**
   * The first public, handwritten symbols without documentation, by unit.
   *
   * The total is the sum of documentable minus documented over units().
   */
  public function undocumented(int $sid, int $limit = 500): array {
    return $this->database->queryRange("SELECT s.id, s.name, s.kind, s.signature, s.unit_id FROM {divengine_codedoc_symbol} s WHERE s.sid = :sid AND s.documented = 0 " . str_replace('{divengine_codedoc_symbol}.id', 's.id', $this->eligible()) . " ORDER BY s.unit_id, s.line, s.id", 0, max(1, $limit), [':sid' => $sid, ':sid2' => $sid], ['fetch' => \PDO::FETCH_ASSOC])->fetchAll();
  }

  /**
   * The symbol and its parents, outermost first.
   */
  public function ancestors(int $sid, int $id): array {
    $chain = [];
    while ($id && count($chain) < 50) {
      $row = $this->database->select('divengine_codedoc_symbol', 's')->fields('s', ['id', 'name', 'parent_id', 'unit_id'])->condition('sid', $sid)->condition('id', $id)->execute()->fetchAssoc();
      if (!$row) {
        break;
      }
      array_unshift($chain, $row);
      $id = (int) $row['parent_id'];
    }
    return $chain;
  }

  /**
   * Documentation an undocumented override inherits: that of the first
   * documented symbol it overrides or implements, following the chain.
   */
  public function inheritedDoc(int $sid, int $id): ?array {
    $seen = [];
    while ($id && !isset($seen[$id]) && count($seen) < 20) {
      $seen[$id] = TRUE;
      $target = $this->database->select('divengine_codedoc_ref', 'r')->fields('r', ['target_symbol_id'])->condition('sid', $sid)->condition('symbol_id', $id)->condition('kind', 'overrides')->isNotNull('target_symbol_id')->range(0, 1)->execute()->fetchField();
      if (!$target) {
        return NULL;
      }
      $row = $this->database->select('divengine_codedoc_symbol', 's')->fields('s', ['id', 'name', 'symbol_key', 'documented', 'doc_summary', 'doc_description'])->condition('id', $target)->execute()->fetchAssoc();
      if ($row && $row['documented']) {
        return $row;
      }
      $id = (int) $target;
    }
    return NULL;
  }

  /**
   * SQL condition of the symbols coverage counts: public or exported,
   * handwritten, and not overriding (overrides inherit their documentation).
   */
  protected function eligible(): string {
    return "AND generated = 0 AND (exported = 1 OR visibility = 'public') AND NOT EXISTS (SELECT 1 FROM {divengine_codedoc_ref} r WHERE r.sid = :sid2 AND r.symbol_id = {divengine_codedoc_symbol}.id AND r.kind = 'overrides')";
  }

  public function symbol(int $sid, int $id): ?array {
    $symbol = $this->rows('symbol', $sid, ['id' => $id], 1)[0] ?? NULL;
    if (!$symbol) {
      return NULL;
    }
    foreach (['param', 'tag', 'ref'] as $table) {
      $symbol[$table . 's'] = $this->rows($table, $sid, ['symbol_id' => $id], 500);
    }
    $symbol['members'] = $this->rows('symbol', $sid, ['parent_id' => $id], 500);
    $query = $this->database->select('divengine_codedoc_ref', 'r')->fields('r')->condition('r.sid', $sid)->condition('r.target_symbol_id', $id);
    $query->leftJoin('divengine_codedoc_symbol', 's', 's.id = r.symbol_id');
    $query->leftJoin('divengine_codedoc_unit', 'u', 'u.id = r.unit_id');
    $query->addField('s', 'symbol_key', 'source_symbol_key');
    $query->addField('u', 'name', 'source_unit_name');
    $symbol['incoming'] = $query->orderBy('r.kind')->orderBy('r.id')->range(0, 500)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return $symbol;
  }

  public function write(int $sid, array $units): void {
    foreach ($units as $unit) {
      if ($this->exists('unit', $sid, 'unit_key', $unit['key'])) {
        throw new ContractException('Unit already uploaded: ' . $unit['key']);
      }
      $uid = $this->insert('unit', [
        'sid' => $sid, 'unit_key' => $unit['key'], 'kind' => $unit['kind'], 'name' => $unit['name'],
        'path' => $unit['path'] ?? NULL, 'parent_key' => $unit['parent'] ?? NULL, 'language' => $unit['language'] ?? '',
        'category' => $unit['category'] ?? '', 'is_test' => (int) !empty($unit['is_test']), 'lines' => $unit['lines'] ?? NULL,
        'doc_summary' => $unit['doc']['summary'] ?? NULL, 'doc_description' => $unit['doc']['description'] ?? NULL,
        'extra' => Canonical::json($unit['extra'] ?? []), 'fingerprint' => Canonical::fingerprint($unit),
      ]);
      $this->tags($sid, $uid, NULL, $unit['doc']);
      foreach ($unit['symbols'] as $symbol) {
        if ($this->exists('symbol', $sid, 'symbol_key', $symbol['key'])) {
          throw new ContractException('Symbol already uploaded: ' . $symbol['key']);
        }
        $doc = $symbol['doc'];
        $id = $this->insert('symbol', [
          'sid' => $sid, 'unit_id' => $uid, 'symbol_key' => $symbol['key'], 'parent_key' => $symbol['parent'] ?? NULL,
          'kind' => $symbol['kind'], 'name' => $symbol['name'], 'qualified_name' => $symbol['qualified_name'] ?? NULL,
          'visibility' => $symbol['visibility'] ?? NULL, 'modifiers' => implode(' ', $symbol['modifiers']),
          'signature' => $symbol['signature'] ?? NULL, 'type_text' => $symbol['type'] ?? NULL,
          'value' => isset($symbol['value']) ? (is_scalar($symbol['value']) ? (string) $symbol['value'] : Canonical::json($symbol['value'])) : NULL,
          'file' => $symbol['file'] ?? $unit['path'] ?? NULL, 'line' => $symbol['line'] ?? NULL, 'end_line' => $symbol['end_line'] ?? NULL,
          'exported' => (int) !empty($symbol['exported']), 'generated' => (int) !empty($symbol['generated']),
          'deprecated' => (int) (!empty($symbol['deprecated']) || in_array('deprecated', array_column($doc['tags'] ?? [], 'kind'), TRUE)),
          'documented' => (int) (!empty($doc['summary']) || !empty($doc['description']) || !empty($doc['tags'])),
          'doc_summary' => $doc['summary'] ?? NULL, 'doc_description' => $doc['description'] ?? NULL,
          'extra' => Canonical::json($symbol['extra'] ?? []), 'fingerprint' => Canonical::fingerprint($symbol),
        ]);
        $this->tags($sid, $uid, $id, $doc);
        foreach ($symbol['params'] as $position => $param) {
          $this->insert('param', ['sid' => $sid, 'symbol_id' => $id, 'position' => $position, 'kind' => $param['kind'] ?? 'param', 'name' => $param['name'], 'type_text' => $param['type'] ?? NULL, 'optional' => (int) !empty($param['optional']), 'rest' => (int) !empty($param['rest']), 'default_value' => isset($param['default']) ? (string) $param['default'] : NULL, 'doc' => is_array($param['doc'] ?? NULL) ? Canonical::json($param['doc']) : ($param['doc'] ?? NULL)]);
        }
        foreach ($symbol['refs'] as $ref) {
          $this->reference($sid, $uid, $id, $ref);
        }
      }
      foreach ($unit['refs'] as $ref) {
        $this->reference($sid, $uid, NULL, $ref);
      }
      foreach ($unit['endpoints'] as $endpoint) {
        $this->insert('endpoint', ['sid' => $sid, 'unit_id' => $uid, 'symbol_key' => $endpoint['symbol'] ?? NULL, 'kind' => $endpoint['kind'], 'method' => $endpoint['method'] ?? NULL, 'path' => $endpoint['path'] ?? NULL, 'full_path' => $endpoint['full_path'] ?? NULL, 'raw' => $endpoint['raw'] ?? NULL, 'line' => $endpoint['line'] ?? NULL, 'detail' => Canonical::json($endpoint['detail'] ?? [])]);
      }
    }
  }

  protected function reference(int $sid, int $unit, ?int $symbol, array $ref): void {
    $this->insert('ref', ['sid' => $sid, 'unit_id' => $unit, 'symbol_id' => $symbol, 'source_key' => $ref['from'] ?? NULL, 'kind' => $ref['kind'], 'target_key' => $ref['target'], 'display' => $ref['display'] ?? NULL, 'external' => (int) !empty($ref['external']), 'line' => $ref['line'] ?? NULL, 'count' => $ref['count'] ?? 1, 'detail' => Canonical::json($ref['detail'] ?? [])]);
  }

  protected function tags(int $sid, int $unit, ?int $symbol, array $doc): void {
    foreach ($doc['tags'] ?? [] as $position => $tag) {
      $this->insert('tag', ['sid' => $sid, 'unit_id' => $unit, 'symbol_id' => $symbol, 'position' => $position, 'kind' => $tag['kind'], 'name' => $tag['name'] ?? NULL, 'text' => $tag['text'] ?? NULL]);
    }
  }

  protected function exists(string $table, int $sid, string $column, string $key): bool {
    return (bool) $this->database->select('divengine_codedoc_' . $table, 'f')->condition('sid', $sid)->condition($column, $key)->countQuery()->execute()->fetchField();
  }

  protected function insert(string $table, array $row): int {
    // Validate lengths before SQL; malformed extractor data yields a useful 422.
    static $schema;
    if ($schema === NULL) {
      require_once dirname(__DIR__) . '/divengine_codedocs.install';
      $schema = divengine_codedocs_schema();
    }
    foreach ($row as $key => $value) {
      $spec = $schema['divengine_codedoc_' . $table]['fields'][$key];
      if ($value !== NULL && (is_array($value) || is_object($value) || (isset($spec['length']) && mb_strlen((string) $value) > $spec['length']))) {
        throw new ContractException('Invalid or oversized field: ' . $table . '.' . $key);
      }
    }
    return (int) $this->database->insert('divengine_codedoc_' . $table)->fields($row)->execute();
  }

  /** Resolve across chunks with correlated queries scoped to this snapshot. */
  public function finalize(int $sid): array {
    $symbol = '{divengine_codedoc_symbol}';
    $unit = '{divengine_codedoc_unit}';
    foreach ([['symbol', 'parent_id', 'parent_key'], ['ref', 'symbol_id', 'source_key'], ['ref', 'target_symbol_id', 'target_key'], ['endpoint', 'symbol_id', 'symbol_key']] as [$table, $column, $key]) {
      $filter = $column === 'target_symbol_id' ? ' AND external = 0' : '';
      $this->database->query("UPDATE {divengine_codedoc_$table} SET $column = (SELECT MIN(s.id) FROM $symbol s WHERE s.sid = :target AND s.symbol_key = {divengine_codedoc_$table}.$key) WHERE sid = :sid AND $key IS NOT NULL $filter", [':target' => $sid, ':sid' => $sid]);
    }
    // Resolve qualified names only when unique; never pick an arbitrary overload.
    $this->database->query("UPDATE {divengine_codedoc_ref} SET target_symbol_id = (SELECT MIN(s.id) FROM $symbol s WHERE s.sid = :target AND s.qualified_name = {divengine_codedoc_ref}.target_key HAVING COUNT(*) = 1) WHERE sid = :sid AND target_symbol_id IS NULL AND external = 0", [':target' => $sid, ':sid' => $sid]);
    $this->database->query("UPDATE {divengine_codedoc_ref} SET target_unit_id = (SELECT MIN(u.id) FROM $unit u WHERE u.sid = :target AND u.unit_key = {divengine_codedoc_ref}.target_key) WHERE sid = :sid AND external = 0", [':target' => $sid, ':sid' => $sid]);
    $missing = $this->database->select('divengine_codedoc_symbol', 's')->condition('sid', $sid)->isNotNull('parent_key')->isNull('parent_id')->countQuery()->execute()->fetchField();
    if ($missing) {
      throw new ContractException('Unresolved symbol parents: ' . $missing);
    }
    $parents = $this->database->select('divengine_codedoc_symbol', 's')->fields('s', ['id', 'parent_id'])->condition('sid', $sid)->execute()->fetchAllKeyed();
    $visited = [];
    foreach ($parents as $id => $parent) {
      $path = [];
      while ($id && !isset($visited[$id])) {
        if (isset($path[$id])) {
          throw new ContractException('Cyclic symbol parents.');
        }
        $path[$id] = TRUE;
        $id = $parents[$id] ?? NULL;
      }
      $visited += $path;
    }
    $kinds = $this->database->query("SELECT kind, COUNT(*) FROM $symbol WHERE sid = :sid GROUP BY kind", [':sid' => $sid])->fetchAllKeyed();
    $count = fn ($table, $where = '') => (int) $this->database->query("SELECT COUNT(*) FROM {divengine_codedoc_$table} WHERE sid = :sid $where", [':sid' => $sid])->fetchField();
    $eligible = $this->eligible();
    $coverage = fn ($suffix) => (int) $this->database->query("SELECT COUNT(*) FROM $symbol WHERE sid = :sid $eligible $suffix", [':sid' => $sid, ':sid2' => $sid])->fetchField();
    return ['unit_count' => $count('unit'), 'symbol_count' => $count('symbol', 'AND parent_id IS NULL'), 'member_count' => $count('symbol', 'AND parent_id IS NOT NULL'), 'ref_count' => $count('ref'), 'endpoint_count' => $count('endpoint'), 'documentable_count' => $coverage(''), 'documented_count' => $coverage('AND documented = 1'), 'kinds' => Canonical::json($kinds)];
  }

  public function fingerprints(int $sid): array {
    $out = [];
    foreach (['unit' => 'unit_key', 'symbol' => 'symbol_key'] as $table => $key) {
      $rows = $this->database->select('divengine_codedoc_' . $table, 'f')->fields('f', [$key, 'fingerprint'])->condition('sid', $sid)->orderBy($key)->execute()->fetchAllKeyed();
      $out[$table] = $rows;
    }
    return $out;
  }

  public function diff(int $from, int $to): array {
    $a = $this->fingerprints($from);
    $b = $this->fingerprints($to);
    $out = [];
    foreach ($a as $table => $values) {
      $out[$table] = ['added' => array_keys(array_diff_key($b[$table], $values)), 'removed' => array_keys(array_diff_key($values, $b[$table])), 'changed' => array_keys(array_filter(array_intersect_key($b[$table], $values), fn ($hash, $key) => $hash !== $values[$key], ARRAY_FILTER_USE_BOTH))];
    }
    return $out;
  }

}
