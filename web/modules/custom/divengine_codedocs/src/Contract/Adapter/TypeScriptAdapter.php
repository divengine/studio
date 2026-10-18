<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract\Adapter;

use Drupal\divengine_codedocs\Contract\ContractException;

/** Adapter for the unchanged CodeIntel TypeScript extractor output. */
class TypeScriptAdapter implements CodeDocsAdapterInterface {

  public function id(): string { return 'tsdoc'; }
  public function label(): string { return 'CodeIntel TypeScript JSON'; }
  public function chunkKeys(): array { return ['modules']; }
  public function applies(array $document): bool {
    return ($document['language'] ?? '') === 'typescript' || isset($document['modules']);
  }

  public function meta(array $header): array {
    if (($header['schema'] ?? NULL) !== 1) {
      throw new ContractException('TypeScript schema must be 1.');
    }
    return array_intersect_key($header, array_flip(['project', 'commit', 'branch', 'captured_at'])) + ['language' => 'typescript', 'producer' => 'codeintel-tsdoc'];
  }

  public function units(array $chunk, array $header): array {
    $units = [];
    foreach ($chunk['modules'] ?? [] as $module) {
      $symbols = [];
      foreach ($module['symbols'] ?? [] as $symbol) {
        $this->symbols($symbol, NULL, $module['path'], $symbols);
      }
      $refs = [];
      foreach ($module['imports'] ?? [] as $import) {
        $refs[] = ['kind' => 'imports', 'target' => ($import['target'] ?? NULL) ?: ($import['external'] ?? $import['source']), 'external' => !empty($import['external']), 'display' => $import['source'], 'line' => $import['line'] ?? NULL, 'detail' => $import];
      }
      foreach ($module['refs'] ?? [] as $ref) {
        $refs[] = ['kind' => $ref['kind'], 'target' => $ref['to'], 'from' => $ref['from'] ?? NULL, 'count' => $ref['count'] ?? 1, 'line' => $ref['line'] ?? NULL];
      }
      $endpoints = [];
      foreach ($module['routes'] ?? [] as $route) {
        $endpoints[] = ['kind' => 'route', 'path' => $route['path'] ?? NULL, 'full_path' => $route['full'] ?? '/', 'symbol' => $route['component'] ?? NULL, 'line' => $route['line'] ?? NULL, 'detail' => $route];
      }
      foreach ($module['http'] ?? [] as $call) {
        $endpoints[] = ['kind' => 'http_call', 'method' => $call['method'], 'path' => $call['path'] ?? NULL, 'symbol' => $call['from'] ?? NULL, 'raw' => $call['raw'] ?? NULL, 'line' => $call['line'] ?? NULL, 'detail' => $call];
      }
      $units[] = ['key' => $module['key'], 'kind' => 'module', 'category' => $module['kind'] ?? 'other', 'name' => $module['name'], 'path' => $module['path'], 'language' => 'typescript', 'is_test' => $module['isTest'] ?? FALSE, 'lines' => $module['lines'] ?? NULL, 'doc' => $module['doc'] ?? [], 'symbols' => $symbols, 'refs' => $refs, 'endpoints' => $endpoints];
    }
    return $units;
  }

  protected function symbols(array $symbol, ?string $parent, string $file, array &$out): void {
    $members = $symbol['members'] ?? [];
    unset($symbol['members']);
    $symbol['doc'] ??= [];
    $refs = [];
    foreach (['props' => 'props', 'lazy' => 'lazy'] as $field => $kind) {
      if (!empty($symbol[$field])) {
        $refs[] = ['kind' => $kind, 'target' => $symbol[$field]];
      }
    }
    $out[] = $symbol + ['parent' => $parent, 'file' => $file, 'end_line' => $symbol['endLine'] ?? NULL, 'modifiers' => array_values(array_filter(['async', 'readonly', 'static', 'abstract', 'optional'], fn ($key) => !empty($symbol[$key]))), 'refs' => $refs, 'extra' => $symbol];
    foreach ($members as $member) {
      $this->symbols($member, $symbol['key'], $file, $out);
    }
  }

}
