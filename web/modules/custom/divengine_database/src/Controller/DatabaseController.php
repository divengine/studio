<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Drupal\divengine_database\PageElementsTrait;
use Drupal\divengine_database\SchemaFacts;
use Drupal\divengine_database\SnapshotImporter;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pages of the database catalog: lists, tables, the explorer and evolution.
 */
class DatabaseController extends ControllerBase {

  use PageElementsTrait;

  protected SchemaFacts $facts;

  protected SnapshotImporter $importer;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->facts = $container->get('divengine_database.facts');
    $instance->importer = $container->get('divengine_database.importer');
    return $instance;
  }

  /**
   * Every database the user may see.
   */
  public function databases(): array {
    $storage = $this->entityTypeManager->getStorage('schema_database');
    $databases = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->sort('name')->execute());
    return $this->page() + [
      'intro' => ['#markup' => '<p class="dv-intro">' . $this->t('The database schemas of your Developments, captured as snapshots by an analyzer (SchemaSpy or any tool that speaks the Divengine contract). Studio keeps the structure, never the data.') . '</p>'],
      'list' => $this->section($this->t('Databases'), count($databases), $this->databaseTable($databases, TRUE)),
    ];
  }

  /**
   * The Databases tab of a Development.
   */
  public function development(DevelopmentInterface $development): array {
    $storage = $this->entityTypeManager->getStorage('schema_database');
    $databases = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('development', $development->id())->sort('name')->execute());
    $add = $this->entityTypeManager->getAccessControlHandler('schema_database')->createAccess() && $development->access('update')
      ? $this->button($this->t('Register database'), Url::fromRoute('entity.schema_database.add_form', [], ['query' => ['development' => $development->id()]]), TRUE)
      : NULL;
    return $this->page() + [
      'intro' => ['#markup' => '<p class="dv-intro">' . $this->t('The databases of this Development. Each snapshot is the schema at a moment: explore it, compare it with another one, and let agents read it through MCP.') . '</p>'],
      'list' => $this->section($this->t('Databases'), count($databases), $this->databaseTable($databases, FALSE), $add),
    ];
  }

  public function developmentTitle(DevelopmentInterface $development): string {
    return (string) $this->t('@development: databases', ['@development' => $development->label()]);
  }

  /**
   * One table of a snapshot: columns, indexes, relationships, findings.
   */
  public function table(SchemaSnapshot $schema_snapshot, int $table): array {
    $sid = (int) $schema_snapshot->id();
    $row = $this->facts->table($sid, $table) ?? throw new NotFoundHttpException();
    $facts = $this->facts->tableFacts($row);
    $database = $schema_snapshot->getDatabase();
    $build = $this->page();
    $build['status'] = $this->statusBar((string) $row->name, [
      (string) $this->t('Kind') => $row->obj_type === 'view' ? $this->t('View') : $this->t('Table'),
      (string) $this->t('Database') => $database?->label(),
      (string) $this->t('Snapshot') => $schema_snapshot->label(),
      (string) $this->t('Catalog') => $row->catalog,
    ], [
      $this->button($this->t('Explore around'), Url::fromRoute('divengine_database.explorer', ['schema_snapshot' => $sid], ['query' => ['table' => $row->id]]), TRUE),
      $this->button($this->t('Snapshot'), $schema_snapshot->toUrl()),
    ]);
    if ($row->comment) {
      $build['comment'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $row->comment, '#attributes' => ['class' => ['dv-intro']], '#weight' => -90];
    }

    $rows = [];
    foreach ($facts['columns'] as $column) {
      $keys = [];
      if ($column->pk_position) {
        $keys[] = $this->badge($this->t('PK @n', ['@n' => $column->pk_position]), 'warning');
      }
      if ($column->auto_increment) {
        $keys[] = $this->badge($this->t('auto'), 'neutral');
      }
      $rows[] = [
        'data' => [
          (string) $column->position,
          ['data' => ['#markup' => '<strong>' . htmlspecialchars($column->name) . '</strong>']],
          ['data' => $this->code(SchemaFacts::shortType($column->data_type, $column->size, $column->scale) . ($column->enum_values ? ' ' . $column->enum_values : ''))],
          $column->nullable ? $this->t('null') : $this->t('not null'),
          ['data' => $column->has_default ? $this->code((string) $column->default_value) : ['#markup' => '']],
          ['data' => $keys],
          (string) $column->comment,
        ],
        'id' => 'column-' . $column->name,
      ];
    }
    $build['columns'] = $this->section($this->t('Columns'), count($rows), [
      '#type' => 'table',
      '#header' => ['#', $this->t('Name'), $this->t('Type'), $this->t('Null'), $this->t('Default'), $this->t('Keys'), $this->t('Comment')],
      '#rows' => $rows,
      '#attributes' => ['id' => 'columns', 'class' => ['dv-db-columns']],
    ]) + ['#weight' => -80];

    $rows = [];
    foreach ($facts['indexes'] as $index) {
      $rows[] = [['data' => $this->code($index->name)], ['data' => $this->code(implode(', ', $index->columns))], ['data' => $index->unique ? $this->badge($this->t('unique'), 'info') : []]];
    }
    $build['indexes'] = $this->section($this->t('Indexes'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Index'), $this->t('Columns'), ''],
      '#rows' => $rows,
      '#empty' => $this->t('No index.'),
      '#attributes' => ['id' => 'indexes'],
    ]) + ['#weight' => -70];

    $relationship_rows = function (array $fks, bool $outgoing) use ($sid): array {
      $rows = [];
      foreach ($fks as $fk) {
        $other = $outgoing ? $fk->parent_table_id : $fk->child_table_id;
        $pairs = array_map(fn ($p) => ($p[0] ?? '?') . ' → ' . ($p[1] ?? '?'), $fk->pairs ?? []);
        $rows[] = [
          ['data' => Link::fromTextAndUrl($outgoing ? $fk->parent_name : $fk->child_name, Url::fromRoute('divengine_database.table', ['schema_snapshot' => $sid, 'table' => $other]))->toRenderable()],
          ['data' => $this->code(implode(', ', $pairs))],
          ['data' => $this->code($fk->name)],
          ['data' => array_filter([
            $fk->implied ? $this->badge($this->t('implied'), 'warning') : $this->badge($this->t('foreign key'), 'info'),
            $fk->on_delete_cascade ? $this->badge($this->t('on delete cascade'), 'neutral') : NULL,
            $fk->on_update ? $this->badge($this->t('on update @a', ['@a' => mb_strtolower($fk->on_update)]), 'neutral') : NULL,
          ])],
        ];
      }
      return $rows;
    };
    $build['relationships'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'relationships'],
      '#weight' => -60,
      'references' => $this->section($this->t('References'), count($facts['references']), [
        '#type' => 'table',
        '#header' => [$this->t('Table'), $this->t('Columns'), $this->t('Name'), ''],
        '#rows' => $relationship_rows($facts['references'], TRUE),
        '#empty' => $this->t('This table references no other.'),
      ], NULL, $this->t('Tables this one points to.')),
      'referenced_by' => $this->section($this->t('Referenced by'), count($facts['referenced_by']), [
        '#type' => 'table',
        '#header' => [$this->t('Table'), $this->t('Columns'), $this->t('Name'), ''],
        '#rows' => $relationship_rows($facts['referenced_by'], FALSE),
        '#empty' => $this->t('No table references this one.'),
      ], NULL, $this->t('Tables that point to this one.')),
    ];
    $findings = $this->facts->findings($sid, (int) $row->id);
    if ($findings) {
      $build['findings'] = $this->section($this->t('Findings'), count($findings), $this->findingTable($findings, $sid, FALSE)) + ['#weight' => -50];
    }
    if ($row->view_definition) {
      $build['definition'] = $this->section($this->t('Definition'), 0, ['#type' => 'html_tag', '#tag' => 'pre', '#value' => htmlspecialchars($row->view_definition), '#attributes' => ['class' => ['dv-db-sql']]]) + ['#weight' => -40];
    }
    $build['#cache']['tags'] = $schema_snapshot->getCacheTags();
    return $build;
  }

  public function tableTitle(SchemaSnapshot $schema_snapshot, int $table): string {
    $row = $this->facts->table((int) $schema_snapshot->id(), $table);
    return $row ? (string) $row->name : (string) $this->t('Table');
  }

  /**
   * The interactive schema explorer of a snapshot.
   */
  public function explorer(SchemaSnapshot $schema_snapshot, Request $request): array {
    if (!$schema_snapshot->isReady()) {
      throw new NotFoundHttpException();
    }
    $params = ['snapshot_id' => (int) $schema_snapshot->id()];
    if ($table = (int) $request->query->get('table')) {
      $params['table_id'] = $table;
    }
    if ($request->query->get('debug')) {
      $params['debug'] = TRUE;
    }
    return $this->mount('explorer', $params);
  }

  /**
   * The explorer on the union of two snapshots.
   */
  public function diffExplorer(Request $request): array {
    return $this->mount('explorer', ['diff_from' => (int) $request->query->get('from'), 'diff_to' => (int) $request->query->get('to')]);
  }

  /**
   * Schema evolution: what changed between two snapshots.
   */
  public function evolution(SchemaDatabase $schema_database, Request $request): array {
    $to = (int) $request->query->get('to');
    $from = (int) $request->query->get('from');
    if (!$to && ($latest = $schema_database->getLatestSnapshot())) {
      $to = (int) $latest->id();
      $from = $from ?: (int) $latest->getPrevious()?->id();
    }
    return $this->mount('evolution', ['from_id' => $from ?: NULL, 'to_id' => $to ?: NULL]);
  }

  /**
   * Recomputes the findings of a snapshot.
   */
  public function reanalyze(SchemaSnapshot $schema_snapshot): RedirectResponse {
    $this->importer->analyze($schema_snapshot);
    $this->messenger()->addStatus($this->t('Findings recomputed: @n.', ['@n' => $schema_snapshot->count('finding_count')]));
    return new RedirectResponse($schema_snapshot->toUrl()->toString());
  }

  /**
   * The element an OWL page mounts on, with its endpoints.
   */
  protected function mount(string $page, array $params): array {
    $route = fn (string $name, array $parameters = []) => Url::fromRoute($name, $parameters)->toString();
    // A route URL with {name} placeholders for the client to fill.
    $pattern = function (string $name, array $placeholders): string {
      // Numeric tokens satisfy the \d+ requirements of the routes.
      $tokens = array_combine($placeholders, array_map(fn ($i) => (string) (987650 + $i), array_keys($placeholders)));
      return strtr(Url::fromRoute($name, $tokens)->toString(), array_combine($tokens, array_map(fn ($p) => '{' . $p . '}', $placeholders)));
    };
    $module = $this->moduleHandler()->getModule('divengine_database')->getPath();
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['data-divengine-database' => $page, 'class' => ['dv-db-app', 'dv-db-app--' . $page]],
      '#attached' => [
        'library' => ['divengine_database/' . $page],
        'drupalSettings' => [
          'divengineDatabase' => [
            'params' => $params,
            'elkWorker' => base_path() . $module . '/js/vendor/elkjs/elk-worker.min.js',
            'endpoints' => [
              'index' => $route('divengine_database.api.index'),
              'details' => $route('divengine_database.api.details'),
              'search' => $route('divengine_database.api.search'),
              'snapshots' => $route('divengine_database.api.snapshots'),
              'diff' => $route('divengine_database.api.diff'),
              'diffGraph' => $route('divengine_database.api.diff_graph'),
              'diffDetails' => $route('divengine_database.api.diff_details'),
            ],
            'urls' => [
              'explorer' => strtr($pattern('divengine_database.explorer', ['schema_snapshot']), ['{schema_snapshot}' => '{snapshot}']),
              'table' => strtr($pattern('divengine_database.table', ['schema_snapshot', 'table']), ['{schema_snapshot}' => '{snapshot}']),
              'evolution' => $this->evolutionPattern($params),
              'diffExplorer' => $route('divengine_database.diff_explorer'),
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * The evolution page of the database of the pair, when known.
   */
  protected function evolutionPattern(array $params): string {
    $id = $params['diff_to'] ?? $params['to_id'] ?? $params['snapshot_id'] ?? NULL;
    $snapshot = $id ? $this->entityTypeManager->getStorage('schema_snapshot')->load($id) : NULL;
    return $snapshot && $snapshot->getDatabase() ? Url::fromRoute('divengine_database.evolution', ['schema_database' => $snapshot->getDatabase()->id()])->toString() : '';
  }

  protected function page(): array {
    return ['#attached' => ['library' => ['divengine_database/database']], '#cache' => ['tags' => ['schema_database_list', 'schema_snapshot_list']]];
  }

  /**
   * @param \Drupal\divengine_database\Entity\SchemaDatabase[] $databases
   */
  protected function databaseTable(array $databases, bool $with_development): array {
    $rows = [];
    foreach ($databases as $database) {
      $latest = $database->getLatestSnapshot();
      $actions = [];
      if ($latest) {
        $actions[] = $this->button($this->t('Explore'), Url::fromRoute('divengine_database.explorer', ['schema_snapshot' => $latest->id()]), TRUE);
      }
      $row = [
        ['data' => $database->toLink()->toRenderable()],
        ['data' => $this->code($database->getIdentifier())],
        (string) $database->get('engine')->value,
        ['data' => $latest ? $latest->toLink()->toRenderable() : ['#markup' => (string) $this->t('No snapshot yet')]],
        $latest ? (string) ($latest->count('table_count') + $latest->count('view_count')) : '',
        $latest ? (string) ($latest->count('real_fk_count') + $latest->count('implied_fk_count')) : '',
        ['data' => $latest && $latest->count('finding_warning_count') ? $this->badge($this->formatPlural($latest->count('finding_warning_count'), '1 warning', '@count warnings'), 'warning') : []],
        ['data' => $this->actions($actions) ?? []],
      ];
      if ($with_development) {
        array_splice($row, 1, 0, [['data' => ['#plain_text' => (string) $database->getDevelopment()?->label()]]]);
      }
      $rows[] = $row;
    }
    $header = [$this->t('Database'), $this->t('Identifier'), $this->t('Engine'), $this->t('Latest snapshot'), $this->t('Tables'), $this->t('Relationships'), '', ''];
    if ($with_development) {
      array_splice($header, 1, 0, [$this->t('Development')]);
    }
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No database yet. Register one in a Development, then import a snapshot.')];
  }

}
