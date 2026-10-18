<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Plugin\Mcp;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Drupal\divengine_database\SchemaDiff;
use Drupal\divengine_database\SchemaFacts;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Database schemas of the Developments for agents.
 *
 * Every answer is limited to what the connected account may see, and is
 * metadata only: there is no row data in Studio.
 */
#[Mcp(
  id: 'database',
  name: new TranslatableMarkup('Divengine Database'),
  description: new TranslatableMarkup('Lets agents list the databases of a Development, read tables with their columns, keys and relationships, see findings and what changed between snapshots.'),
)]
class DatabaseSchema extends McpPluginBase implements ContainerFactoryPluginInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected SchemaFacts $facts;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->facts = $container->get('divengine_database.facts');
    return $instance;
  }

  public function getTools(): array {
    $database = ['type' => 'string', 'description' => 'Database code (e.g. DB-0001), identifier or name.'];
    $snapshot = ['type' => 'string', 'description' => 'Snapshot key (e.g. billing@v12). Defaults to the latest snapshot.'];
    return [
      new Tool(
        name: 'databases',
        description: 'Lists the databases you can see, optionally of one Development (code like DEV-0001), with their engine, latest snapshot and counts. Start here.',
        inputSchema: ['type' => 'object', 'properties' => ['development' => ['type' => 'string', 'description' => 'Development code, e.g. DEV-0001 (optional).']]],
        title: 'Databases',
      ),
      new Tool(
        name: 'tables',
        description: 'Lists the tables and views of a snapshot with column and relationship counts; "text" filters by table or column name.',
        inputSchema: ['type' => 'object', 'properties' => ['database' => $database, 'snapshot' => $snapshot, 'text' => ['type' => 'string']], 'required' => ['database']],
        title: 'Tables',
      ),
      new Tool(
        name: 'table',
        description: 'Describes tables: columns (type, null, default, keys), indexes, the tables they reference and the tables that reference them. Give one name or several separated by commas.',
        inputSchema: ['type' => 'object', 'properties' => ['database' => $database, 'snapshot' => $snapshot, 'name' => ['type' => 'string', 'description' => 'Table name(s), comma separated.']], 'required' => ['database', 'name']],
        title: 'Describe tables',
      ),
      new Tool(
        name: 'findings',
        description: 'Findings of the rules over a snapshot (tables without indexes, orphans, suspicious defaults…), most severe first.',
        inputSchema: ['type' => 'object', 'properties' => ['database' => $database, 'snapshot' => $snapshot], 'required' => ['database']],
        title: 'Findings',
      ),
      new Tool(
        name: 'changes',
        description: 'What changed in the schema from one snapshot to another (default: from the previous snapshot to the latest): added, removed and modified tables, columns, keys, indexes and relationships, with before/after.',
        inputSchema: ['type' => 'object', 'properties' => ['database' => $database, 'from' => ['type' => 'string', 'description' => 'Snapshot key FROM.'], 'to' => ['type' => 'string', 'description' => 'Snapshot key TO.']], 'required' => ['database']],
        title: 'Schema changes',
      ),
    ];
  }

  public function executeTool(string $toolId, mixed $arguments): array {
    $arguments = (array) $arguments;
    try {
      $result = match ($toolId) {
        'databases', md5('databases') => $this->databases($arguments),
        'tables', md5('tables') => $this->tables($arguments),
        'table', md5('table') => $this->describe($arguments),
        'findings', md5('findings') => $this->findings($arguments),
        'changes', md5('changes') => $this->changes($arguments),
        default => throw new \InvalidArgumentException('Unknown tool.'),
      };
    }
    catch (\InvalidArgumentException $e) {
      $result = 'Error: ' . $e->getMessage();
    }
    return [['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]];
  }

  protected function databases(array $arguments): array {
    $storage = $this->entityTypeManager->getStorage('schema_database');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('name');
    if (!empty($arguments['development'])) {
      $developments = $this->entityTypeManager->getStorage('development')->getQuery()->accessCheck(TRUE)->condition('code', mb_strtoupper(trim($arguments['development'])))->execute();
      if (!$developments) {
        throw new \InvalidArgumentException('No Development with that code is visible to you.');
      }
      $query->condition('development', reset($developments));
    }
    $out = [];
    foreach ($storage->loadMultiple($query->execute()) as $database) {
      $latest = $database->getLatestSnapshot();
      $out[] = [
        'code' => $database->get('code')->value,
        'name' => $database->get('name')->value,
        'identifier' => $database->getIdentifier(),
        'engine' => $database->get('engine')->value,
        'development' => $database->getDevelopment()?->label(),
        'description' => $database->get('description')->value,
        'latest_snapshot' => $latest ? [
          'key' => $latest->label(),
          'revision' => $latest->get('source_revision')->value,
          'captured_at' => gmdate('c', (int) $latest->get('captured_at')->value),
          'tables' => $latest->count('table_count'),
          'views' => $latest->count('view_count'),
          'columns' => $latest->count('column_count'),
          'foreign_keys' => $latest->count('real_fk_count'),
          'implied_relationships' => $latest->count('implied_fk_count'),
          'findings' => $latest->count('finding_count'),
        ] : NULL,
      ];
    }
    return $out;
  }

  protected function tables(array $arguments): array {
    $snapshot = $this->snapshot($arguments);
    $text = trim((string) ($arguments['text'] ?? ''));
    $tables = $this->facts->tables((int) $snapshot->id());
    if ($text !== '') {
      $matching = array_flip(array_column($this->facts->search($snapshot, $text, SchemaFacts::MAX_SEARCH_RESULTS), 'table_id'));
      $tables = array_intersect_key($tables, $matching);
    }
    return [
      'snapshot' => $snapshot->label(),
      'tables' => array_values(array_map(fn ($t) => array_filter([
        'name' => $t->name,
        'kind' => $t->obj_type,
        'columns' => (int) $t->columns,
        'references' => (int) $t->references,
        'referenced_by' => (int) $t->referenced_by,
        'comment' => $t->comment,
      ], fn ($v) => $v !== NULL && $v !== ''), $tables)),
    ];
  }

  protected function describe(array $arguments): array {
    $snapshot = $this->snapshot($arguments);
    $sid = (int) $snapshot->id();
    $names = array_filter(array_map('trim', explode(',', (string) ($arguments['name'] ?? ''))));
    if (!$names) {
      throw new \InvalidArgumentException('Give the name of a table.');
    }
    $by_name = [];
    foreach ($this->facts->tables($sid) as $table) {
      $by_name[mb_strtolower($table->name)] = $table;
    }
    $out = [];
    foreach (array_slice($names, 0, 20) as $name) {
      $table = $by_name[mb_strtolower($name)] ?? NULL;
      if (!$table) {
        $out[] = ['name' => $name, 'error' => 'No such table in ' . $snapshot->label() . '. Use database_tables.'];
        continue;
      }
      $row = $this->facts->table($sid, (int) $table->id);
      $facts = $this->facts->tableFacts($row);
      $relation = fn ($fk, bool $outgoing) => array_filter([
        'table' => $outgoing ? $fk->parent_name : $fk->child_name,
        'columns' => array_map(fn ($p) => ($p[0] ?? '?') . ' -> ' . ($p[1] ?? '?'), $fk->pairs ?? []),
        'name' => $fk->name,
        'implied' => (bool) $fk->implied ?: NULL,
        'on_delete_cascade' => (bool) $fk->on_delete_cascade ?: NULL,
      ]);
      $out[] = array_filter([
        'name' => $row->name,
        'kind' => $row->obj_type,
        'comment' => $row->comment,
        'columns' => array_values(array_map(fn ($c) => array_filter([
          'name' => $c->name,
          'type' => SchemaFacts::shortType($c->data_type, $c->size, $c->scale),
          'nullable' => (bool) $c->nullable,
          'default' => $c->has_default ? $c->default_value : NULL,
          'primary_key' => $c->pk_position ? (int) $c->pk_position : NULL,
          'auto_increment' => $c->auto_increment ? TRUE : NULL,
          'enum' => $c->enum_values,
          'comment' => $c->comment,
        ], fn ($v) => $v !== NULL && $v !== ''), $facts['columns'])),
        'indexes' => array_values(array_map(fn ($i) => ['name' => $i->name, 'unique' => $i->unique, 'columns' => $i->columns], $facts['indexes'])),
        'references' => array_values(array_map(fn ($fk) => $relation($fk, TRUE), $facts['references'])),
        'referenced_by' => array_values(array_map(fn ($fk) => $relation($fk, FALSE), $facts['referenced_by'])),
        'view_definition' => $row->view_definition,
      ], fn ($v) => $v !== NULL && $v !== '' && $v !== []);
    }
    return ['snapshot' => $snapshot->label(), 'tables' => $out];
  }

  protected function findings(array $arguments): array {
    $snapshot = $this->snapshot($arguments);
    return [
      'snapshot' => $snapshot->label(),
      'findings' => array_map(fn ($f) => array_filter(['severity' => $f->severity, 'rule' => $f->rule_code, 'table' => $f->table_name, 'detail' => $f->detail]), array_slice($this->facts->findings((int) $snapshot->id()), 0, 300)),
    ];
  }

  protected function changes(array $arguments): array {
    $database = $this->database($arguments);
    $to = !empty($arguments['to']) ? $this->snapshot(['snapshot' => $arguments['to']] + $arguments) : $database->getLatestSnapshot();
    if (!$to) {
      throw new \InvalidArgumentException('This database has no snapshot yet.');
    }
    $from = !empty($arguments['from']) ? $this->snapshot(['snapshot' => $arguments['from']] + $arguments) : $to->getPrevious();
    if (!$from) {
      throw new \InvalidArgumentException(sprintf('%s is the first snapshot: there is nothing to compare it with.', $to->label()));
    }
    $diff = SchemaDiff::diff($this->facts->diffInput($from), $this->facts->diffInput($to));
    return [
      'from' => $from->label(),
      'to' => $to->label(),
      'changed' => $diff['summary']['changed'],
      'by_severity' => $diff['summary']['by_severity'],
      'notes' => $diff['notes'],
      'changes' => array_map(fn ($o) => array_filter([
        'change' => $o['change'],
        'type' => $o['type'],
        'object' => match ($o['type']) { 'table', 'routine' => $o['name'], 'column' => $o['table'] . '.' . $o['name'], default => $o['table'] . ' ' . $o['name'] },
        'severity' => $o['severity'],
        'properties' => array_map(fn ($c) => ['property' => $c['property'], 'before' => $c['before'], 'after' => $c['after']], $o['changes']),
      ]), array_slice($diff['objects'], 0, 500)),
    ];
  }

  /**
   * A visible database by code, identifier or name.
   */
  protected function database(array $arguments): SchemaDatabase {
    $key = trim((string) ($arguments['database'] ?? ''));
    $storage = $this->entityTypeManager->getStorage('schema_database');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $query->condition($query->orConditionGroup()->condition('code', mb_strtoupper($key))->condition('identifier', $key)->condition('name', $key));
    $ids = $query->execute();
    $database = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$database instanceof SchemaDatabase || !$database->access('view')) {
      throw new \InvalidArgumentException('No database with that code, identifier or name is visible to you. Use database_databases.');
    }
    return $database;
  }

  /**
   * A ready snapshot of the database: by key, else the latest.
   */
  protected function snapshot(array $arguments): SchemaSnapshot {
    $database = $this->database($arguments);
    if (!empty($arguments['snapshot'])) {
      $found = $this->entityTypeManager->getStorage('schema_snapshot')->loadByProperties(['database' => $database->id(), 'snapshot_key' => trim($arguments['snapshot']), 'state' => 'ready']);
      $snapshot = reset($found) ?: NULL;
    }
    else {
      $snapshot = $database->getLatestSnapshot();
    }
    return $snapshot instanceof SchemaSnapshot ? $snapshot : throw new \InvalidArgumentException('That snapshot does not exist or is not ready. Use database_databases.');
  }

}
