<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\divengine_core\PageElementsTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page of a snapshot: its counts, tables, findings and routines.
 */
class SnapshotViewBuilder extends EntityViewBuilder {

  use PageElementsTrait;

  protected SchemaFacts $facts;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->facts = $container->get('divengine_database.facts');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $snapshot) {
      foreach (array_keys($snapshot->getFieldDefinitions()) as $field_name) {
        unset($build[$id][$field_name]);
      }
      $build[$id] += $this->snapshot($snapshot);
      $build[$id]['#attached']['library'][] = 'divengine_database/database';
      $build[$id]['#cache']['contexts'][] = 'url.query_args:q';
    }
  }

  protected function snapshot(SchemaSnapshot $snapshot): array {
    $sid = (int) $snapshot->id();
    $database = $snapshot->getDatabase();
    $buttons = [];
    if ($snapshot->isReady()) {
      $buttons[] = $this->button($this->t('Explore schema'), Url::fromRoute('divengine_database.explorer', ['schema_snapshot' => $sid]), TRUE);
      if ($database && ($previous = $snapshot->getPrevious())) {
        $buttons[] = $this->button($this->t('Changes since @key', ['@key' => $previous->label()]), Url::fromRoute('divengine_database.evolution', ['schema_database' => $database->id()], ['query' => ['from' => $previous->id(), 'to' => $sid]]));
      }
    }
    if ($database?->access('update')) {
      $buttons[] = $this->button($this->t('Recompute findings'), Url::fromRoute('divengine_database.reanalyze', ['schema_snapshot' => $sid]));
    }
    if ($snapshot->access('delete')) {
      $buttons[] = $this->button($this->t('Delete'), $snapshot->toUrl('delete-form'));
    }
    $build['status'] = $this->statusBar($snapshot->label(), [
      (string) $this->t('Database') => $database?->label(),
      (string) $this->t('Revision') => $snapshot->get('source_revision')->value,
      (string) $this->t('Analyzer') => trim($snapshot->get('source')->value . ' ' . $snapshot->get('source_version')->value),
      (string) $this->t('Captured') => \Drupal::service('date.formatter')->format((int) $snapshot->get('captured_at')->value, 'short'),
      (string) $this->t('Catalog') => $snapshot->get('catalog')->value,
      (string) $this->t('Content hash') => substr((string) $snapshot->get('content_hash')->value, 0, 16),
    ], $buttons);
    if ($error = $snapshot->get('import_error')->value) {
      $build['error'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => htmlspecialchars($error), '#attributes' => ['class' => ['messages', 'messages--warning']], '#weight' => -95];
    }
    $cards = [];
    foreach (['table_count' => 'info', 'view_count' => 'neutral', 'column_count' => 'neutral', 'index_count' => 'neutral', 'real_fk_count' => 'neutral', 'implied_fk_count' => 'neutral', 'routine_count' => 'neutral', 'finding_count' => $snapshot->count('finding_warning_count') ? 'warning' : 'success'] as $counter => $tone) {
      if ($snapshot->count($counter) || in_array($counter, ['table_count', 'column_count', 'real_fk_count', 'finding_count'], TRUE)) {
        $cards[$counter] = $this->card($snapshot->getFieldDefinition($counter)->getLabel(), number_format($snapshot->count($counter)), $tone);
      }
    }
    $build['cards'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']], '#weight' => -90] + $cards;

    $filter = trim((string) \Drupal::request()->query->get('q', ''));
    $rows = [];
    foreach ($this->facts->tables($sid, $filter) as $table) {
      $rows[] = [
        ['data' => Link::fromTextAndUrl($table->name, Url::fromRoute('divengine_database.table', ['schema_snapshot' => $sid, 'table' => $table->id]))->toRenderable()],
        ['data' => $table->obj_type === 'view' ? $this->badge($this->t('view'), 'info') : []],
        (string) $table->columns,
        (string) $table->references,
        (string) $table->referenced_by,
        ['data' => $table->findings ? $this->badge((string) $table->findings, 'warning') : []],
        mb_strimwidth((string) $table->comment, 0, 90, '…'),
      ];
    }
    $search = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => ['method' => 'get', 'class' => ['dv-db-filter']],
      'q' => ['#type' => 'html_tag', '#tag' => 'input', '#attributes' => ['type' => 'search', 'name' => 'q', 'value' => $filter, 'placeholder' => (string) $this->t('Filter tables…'), 'class' => ['form-text']]],
    ];
    $build['tables'] = $this->section($this->t('Tables'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Table'), '', $this->t('Columns'), $this->t('References'), $this->t('Referenced by'), $this->t('Findings'), $this->t('Comment')],
      '#rows' => $rows,
      '#empty' => $filter !== '' ? $this->t('No table matches %q.', ['%q' => $filter]) : $this->t('No table.'),
    ], $search) + ['#weight' => -70];

    $findings = $this->facts->findings($sid);
    $build['findings'] = $this->section($this->t('Findings'), count($findings), $this->findingTable($findings, $sid), NULL, $this->t('Deterministic rules over the facts. Signals to look at, not proof of defects.')) + ['#weight' => -60];

    if ($routines = $this->facts->routines($sid)) {
      $rows = array_map(fn ($r) => [
        ['data' => $this->code($r->name)],
        $r->routine_type,
        ['data' => $this->code(trim(($r->parameter_signature ?? '') . ($r->return_type ? ' → ' . $r->return_type : '')))],
        (string) $r->sql_data_access,
        (string) $r->comment,
      ], $routines);
      $build['routines'] = $this->section($this->t('Routines'), count($rows), [
        '#type' => 'table',
        '#header' => [$this->t('Routine'), $this->t('Kind'), $this->t('Signature'), $this->t('Data access'), $this->t('Comment')],
        '#rows' => $rows,
      ]) + ['#weight' => -50];
    }
    return $build;
  }

}
