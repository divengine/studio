<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract\Adapter;

use Drupal\divengine_codedocs\Contract\ContractException;

/** The existing CodeIntel JSON doclet, without language-specific storage. */
class JavadocAdapter implements CodeDocsAdapterInterface {

  public function id(): string { return 'javadoc'; }
  public function label(): string { return 'Javadoc JSON doclet'; }
  public function chunkKeys(): array { return ['packages', 'types']; }
  public function applies(array $document): bool {
    return ($document['language'] ?? '') === 'java' || isset($document['packages'], $document['types']);
  }

  public function meta(array $header): array {
    if (($header['schema'] ?? NULL) !== 1) {
      throw new ContractException('Javadoc schema must be 1.');
    }
    return array_intersect_key($header, array_flip(['project', 'commit', 'branch', 'captured_at'])) + ['language' => 'java', 'producer' => 'codeintel-javadoc'];
  }

  public function units(array $chunk, array $header): array {
    $units = [];
    foreach ($chunk['packages'] ?? [] as $package) {
      // Prefix package keys: the unnamed Java package has an empty key.
      $units[] = ['key' => 'package:' . $package['key'], 'name' => $package['name'] ?: '(default)', 'kind' => 'package', 'language' => 'java', 'doc' => $package['doc'] ?? [], 'extra' => $package];
    }
    foreach ($chunk['types'] ?? [] as $type) {
      // One type per unit lets publishers split large packages across requests.
      $symbols = [$this->symbol($type)];
      foreach (['fields' => 'field', 'executables' => 'method'] as $field => $kind) {
        foreach ($type[$field] ?? [] as $member) {
          $member['kind'] ??= $kind;
          $symbol = $this->symbol($member);
          $symbol['parent'] = $type['key'];
          $symbols[] = $symbol;
        }
      }
      $units[] = ['key' => 'type:' . $type['key'], 'kind' => 'file', 'name' => $type['name'], 'path' => $type['file'] ?? NULL, 'parent' => 'package:' . ($type['package'] ?? ''), 'language' => 'java', 'symbols' => $symbols];
    }
    return $units;
  }

  protected function symbol(array $value): array {
    $refs = [];
    foreach (['superclass', 'interfaces', 'permits', 'type', 'returnType', 'thrown'] as $role) {
      $types = $value[$role] ?? [];
      foreach (array_is_list($types) ? $types : [$types] as $type) {
        $this->typeRefs($type, $role, $refs);
      }
    }
    $params = [];
    foreach (['parameters' => 'param', 'typeParameters' => 'type_param', 'recordComponents' => 'component'] as $field => $kind) {
      foreach ($value[$field] ?? [] as $param) {
        $params[] = ['kind' => $kind, 'name' => $param['name'], 'type' => $param['type']['display'] ?? implode(' & ', array_column($param['bounds'] ?? [], 'display')), 'rest' => $param['varargs'] ?? FALSE, 'doc' => $param['doc'] ?? ''];
        $this->typeRefs($param['type'] ?? [], $kind, $refs);
        foreach ($param['bounds'] ?? [] as $bound) {
          $this->typeRefs($bound, 'bound', $refs);
        }
      }
    }
    foreach ($value['annotations'] ?? [] as $annotation) {
      $this->typeRefs($annotation['type'] ?? [], 'annotation', $refs);
    }
    foreach ($value['doc']['links'] ?? [] as $link) {
      $refs[] = ['kind' => 'doc_link', 'target' => $link['target'], 'display' => $link['label'] ?? $link['target']];
    }
    if (!empty($value['overrides']['key'])) {
      $refs[] = ['kind' => 'overrides', 'target' => $value['overrides']['key']];
    }
    $extra = $value;
    unset($extra['fields'], $extra['executables']);
    $modifiers = array_values(array_filter(['static', 'abstract', 'final', 'sealed', 'default', 'synchronized', 'native'], fn ($flag) => !empty($value[$flag])));
    return [
      'key' => $value['key'], 'name' => $value['name'], 'kind' => $value['kind'],
      'qualified_name' => $value['key'], 'parent' => $value['enclosing'] ?? NULL,
      'visibility' => $value['visibility'] ?? 'package', 'modifiers' => $modifiers,
      'type' => $value['type']['display'] ?? $value['returnType']['display'] ?? NULL,
      'signature' => $value['signature'] ?? $value['name'] . (isset($value['parameters']) ? '(' . implode(', ', array_map(fn ($p) => ($p['type']['display'] ?? '?') . ' ' . $p['name'], $value['parameters'])) . ')' : ''),
      'value' => $value['constantValue'] ?? $value['value'] ?? NULL,
      'file' => $value['file'] ?? NULL, 'line' => $value['line'] ?? NULL,
      'generated' => $value['generated'] ?? FALSE, 'deprecated' => $value['deprecated'] ?? FALSE,
      'exported' => ($value['visibility'] ?? '') === 'public', 'doc' => $value['doc'] ?? [],
      'params' => $params, 'refs' => $refs, 'extra' => $extra,
    ];
  }

  protected function typeRefs(array $type, string $role, array &$refs): void {
    if (!empty($type['qualifiedName'])) {
      $refs[] = ['kind' => $role, 'target' => $type['qualifiedName'], 'display' => $type['display'] ?? $type['qualifiedName'], 'external' => empty($type['internal']), 'detail' => $type];
    }
    foreach ($type['args'] ?? [] as $arg) {
      $this->typeRefs($arg, 'argument', $refs);
    }
    foreach (['component', 'bound'] as $field) {
      if (!empty($type[$field])) {
        $this->typeRefs($type[$field], $field, $refs);
      }
    }
  }

}
