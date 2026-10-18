<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract;

/** Validation and stable fingerprints, usable without a Drupal bootstrap. */
class Canonical {

  public static function json(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  public static function hash(mixed $value, bool $content = FALSE): string {
    $sort = function ($value) use (&$sort, $content) {
      if (!is_array($value)) {
        return $value;
      }
      if (!array_is_list($value)) {
        if ($content) {
          foreach (['line', 'endLine', 'end_line', 'file', 'lines'] as $key) {
            unset($value[$key]);
          }
        }
        ksort($value, SORT_STRING);
      }
      return array_map($sort, $value);
    };
    return hash('sha256', static::json($sort($value)));
  }

  public static function fingerprint(array $value): string {
    unset($value['symbols'], $value['members']);
    return substr(static::hash($value, TRUE), 0, 40);
  }

  public static function units(array $units): array {
    $keys = $symbols = [];
    foreach ($units as &$unit) {
      static::key($unit['key'] ?? NULL, 'unit');
      if (isset($keys[$unit['key']])) {
        throw new ContractException('Duplicate unit key: ' . $unit['key']);
      }
      $keys[$unit['key']] = TRUE;
      $unit['doc'] ??= [];
      $unit += ['kind' => 'module', 'name' => $unit['key'], 'symbols' => [], 'refs' => [], 'endpoints' => [], 'doc' => []];
      static::lists($unit, ['symbols', 'refs', 'endpoints']);
      static::doc($unit['doc']);
      foreach ($unit['symbols'] as &$symbol) {
        static::key($symbol['key'] ?? NULL, 'symbol');
        if (isset($symbols[$symbol['key']])) {
          throw new ContractException('Duplicate symbol key: ' . $symbol['key']);
        }
        $symbols[$symbol['key']] = TRUE;
        $symbol['doc'] ??= [];
        $symbol += ['kind' => 'symbol', 'name' => $symbol['key'], 'params' => [], 'refs' => [], 'doc' => [], 'modifiers' => []];
        static::lists($symbol, ['params', 'refs', 'modifiers']);
        static::doc($symbol['doc']);
        foreach ($symbol['params'] as $param) {
          static::key($param['name'] ?? NULL, 'parameter name');
        }
        static::refs($symbol['refs']);
      }
      unset($symbol);
      static::refs($unit['refs']);
      foreach ($unit['endpoints'] as $endpoint) {
        static::key($endpoint['kind'] ?? NULL, 'endpoint kind');
      }
    }
    unset($unit);
    return $units;
  }

  protected static function key(mixed $value, string $label): void {
    if (!is_string($value) || trim($value) === '' || strlen($value) > 4096 || mb_strlen($value) > 1024) {
      throw new ContractException('Missing or invalid ' . $label . ' (maximum 1024 characters).');
    }
  }

  protected static function lists(array $value, array $fields): void {
    foreach ($fields as $field) {
      if (!is_array($value[$field]) || !array_is_list($value[$field])) {
        throw new ContractException($field . ' must be a JSON array.');
      }
    }
  }

  protected static function doc(mixed $doc): void {
    if (!is_array($doc)) {
      throw new ContractException('doc must be an object.');
    }
    if (isset($doc['tags'])) {
      static::lists($doc, ['tags']);
      foreach ($doc['tags'] as $tag) {
        static::key($tag['kind'] ?? NULL, 'tag kind');
      }
    }
  }

  protected static function refs(array $refs): void {
    foreach ($refs as $ref) {
      static::key($ref['target'] ?? NULL, 'reference target');
      static::key($ref['kind'] ?? NULL, 'reference kind');
    }
  }

}
