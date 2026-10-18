<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Contract\Adapter;

use Drupal\divengine_database\Contract\Canonical;
use Drupal\divengine_database\Contract\ContractException;

/**
 * SchemaSpy's XML dump (<database><tables><table>…) as a contract.
 *
 * SchemaSpy writes it next to its HTML (e.g. mydb.mydb.xml), for any engine
 * it supports. Real foreign keys are grouped by name (composite keys keep
 * their order); each implied relationship is its own single-column one.
 * The XML has no routines: an enriching analyzer can send them in the
 * contract.
 */
class SchemaSpyAdapter implements ContractAdapterInterface {

  public function id(): string {
    return 'schemaspy';
  }

  public function label(): string {
    return 'SchemaSpy XML';
  }

  public function applies(string $content, string $filename = ''): bool {
    $head = substr(ltrim($content), 0, 4096);
    return str_starts_with($head, '<') && preg_match('/<database\b/', $head) === 1;
  }

  public function toContract(string $content): array {
    $previous = libxml_use_internal_errors(TRUE);
    // No network and no entity expansion: the XML is untrusted input.
    $root = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_use_internal_errors($previous);
    if ($root === FALSE || $root->getName() !== 'database') {
      throw new ContractException('The file is not a SchemaSpy XML dump (no <database> root).');
    }
    $catalog = (string) ($root['schema'] ?? '') ?: (string) ($root['name'] ?? '');
    $name = (string) ($root['name'] ?? '') ?: $catalog;
    $engine = trim(explode('-', (string) ($root['type'] ?? ''))[0]) ?: 'unknown';

    $tables = [];
    $foreign_keys = [];
    $seen = [];
    foreach ($root->tables->table ?? [] as $t) {
      $t_name = (string) $t['name'];
      $t_catalog = (string) ($t['catalog'] ?? '') ?: $catalog;
      $t_key = $t_catalog . '.' . $t_name;
      $pk = [];
      foreach ($t->primaryKey ?? [] as $p) {
        if ((string) $p['column'] !== '') {
          $pk[(string) $p['column']] = self::int($p['sequenceNumberInPK']) ?: count($pk) + 1;
        }
      }
      $columns = [];
      foreach ($t->column ?? [] as $c) {
        $c_name = (string) $c['name'];
        $default = isset($c['defaultValue']) ? (string) $c['defaultValue'] : NULL;
        // SchemaSpy writes the lowercase string "null" for "no default".
        $has_default = $default !== NULL && $default !== 'null';
        $columns[] = [
          'logical_key' => $t_key . '.' . $c_name,
          'name' => $c_name,
          // SchemaSpy's id is the 0-based ordinal; positions are 1-based.
          'position' => (self::int($c['id']) ?? 0) + 1,
          'data_type' => (string) ($c['type'] ?? ''),
          'jdbc_type_code' => self::int($c['typeCode']),
          'size' => self::int($c['size']),
          'scale' => self::int($c['digits']),
          'nullable' => self::bool($c['nullable'], TRUE),
          'has_default' => $has_default,
          'default_value' => $has_default ? $default : NULL,
          'auto_updated' => self::bool($c['autoUpdated']),
          'comment' => self::text($c['remarks']),
          'pk_position' => $pk[$c_name] ?? 0,
        ];
      }
      $indexes = [];
      foreach ($t->index ?? [] as $i) {
        $members = [];
        $position = 0;
        foreach ($i->column ?? [] as $ic) {
          $members[] = [
            'column_logical_key' => $t_key . '.' . (string) $ic['name'],
            'position' => ++$position,
            'ascending' => self::bool($ic['ascending'], TRUE),
          ];
        }
        $indexes[] = [
          'logical_key' => $t_key . '.idx:' . (string) $i['name'],
          'name' => (string) $i['name'],
          'is_unique' => self::bool($i['unique']),
          'columns' => $members,
        ];
      }
      $tables[] = [
        'logical_key' => $t_key,
        'name' => $t_name,
        'catalog' => $t_catalog,
        'obj_type' => strtoupper((string) ($t['type'] ?? '')) === 'VIEW' ? 'view' : 'table',
        'comment' => self::text($t['remarks']),
        'columns' => $columns,
        'indexes' => $indexes,
      ];

      // Relationships live on the child side, as <parent> in each column.
      $real = [];
      foreach ($t->column ?? [] as $c) {
        $child = (string) $c['name'];
        foreach ($c->parent ?? [] as $p) {
          $fk_name = (string) ($p['foreignKey'] ?? '') ?: 'fk';
          $parent_table = (string) $p['table'];
          $parent_catalog = (string) ($p['catalog'] ?? '') ?: $t_catalog;
          $pair = [
            'child_column_key' => $t_key . '.' . $child,
            'parent_column_key' => $parent_catalog . '.' . $parent_table . '.' . (string) $p['column'],
          ];
          if (self::bool($p['implied'])) {
            $key = "$t_key.$child->$parent_table." . (string) $p['column'] . '#implied';
            if (isset($seen[$key])) {
              continue;
            }
            $seen[$key] = TRUE;
            $foreign_keys[] = [
              'logical_key' => $key,
              'name' => $fk_name,
              'implied' => TRUE,
              'on_delete_cascade' => self::bool($p['onDeleteCascade']),
              'child_table_key' => $t_key,
              'parent_table_key' => $parent_catalog . '.' . $parent_table,
              'columns' => [$pair + ['position' => 1]],
            ];
            continue;
          }
          $real[$fk_name] ??= [
            'logical_key' => $t_key . '.' . $fk_name,
            'name' => $fk_name,
            'implied' => FALSE,
            'on_delete_cascade' => self::bool($p['onDeleteCascade']),
            'child_table_key' => $t_key,
            'parent_table_key' => $parent_catalog . '.' . $parent_table,
            'columns' => [],
          ];
          $real[$fk_name]['columns'][] = $pair + ['position' => count($real[$fk_name]['columns']) + 1];
        }
      }
      foreach ($real as $fk) {
        if (!isset($seen[$fk['logical_key']])) {
          $seen[$fk['logical_key']] = TRUE;
          $foreign_keys[] = $fk;
        }
      }
    }

    $payload = [
      'contract_version' => Canonical::CONTRACT_VERSION,
      'database' => ['code' => $catalog, 'name' => $name, 'engine' => $engine],
      'snapshot' => [
        'captured_at' => gmdate('c'),
        'source' => 'schemaspy',
        'source_revision' => NULL,
        'catalog' => $catalog,
      ],
      'tables' => $tables,
      'foreign_keys' => $foreign_keys,
    ];
    return $payload;
  }

  private static function bool(mixed $value, bool $default = FALSE): bool {
    return $value === NULL ? $default : strtolower(trim((string) $value)) === 'true';
  }

  private static function int(mixed $value): ?int {
    $value = $value === NULL ? '' : trim((string) $value);
    return preg_match('/^-?\d+$/', $value) ? (int) $value : NULL;
  }

  private static function text(mixed $value): ?string {
    $value = $value === NULL ? '' : trim((string) $value);
    return $value === '' ? NULL : $value;
  }

}
