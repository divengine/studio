<?php

declare(strict_types=1);

namespace Drupal\divengine_database;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Url;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_database\Entity\SchemaSnapshot;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page of a database: its latest schema, its snapshots and how to feed
 * it.
 */
class DatabaseViewBuilder extends EntityViewBuilder {

  use PageElementsTrait;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $database) {
      foreach (array_keys($database->getFieldDefinitions()) as $field_name) {
        if ($field_name !== 'thread') {
          unset($build[$id][$field_name]);
        }
      }
      $build[$id] += $this->database($database);
      $build[$id]['#attached']['library'][] = 'divengine_database/database';
      $build[$id]['#cache']['tags'][] = 'schema_snapshot_list';
    }
  }

  protected function database(SchemaDatabase $database): array {
    $snapshots = $this->entityTypeManager->getStorage('schema_snapshot');
    $history = $snapshots->loadMultiple($snapshots->getQuery()->accessCheck(FALSE)->condition('database', $database->id())->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 30)->execute());
    $ready = array_filter($history, fn (SchemaSnapshot $s) => $s->isReady());
    $latest = reset($ready) ?: NULL;
    $manage = $database->access('update');

    $buttons = [];
    if ($latest) {
      $buttons[] = $this->button($this->t('Explore schema'), Url::fromRoute('divengine_database.explorer', ['schema_snapshot' => $latest->id()]), TRUE);
      if (count($ready) > 1) {
        $buttons[] = $this->button($this->t('Evolution'), Url::fromRoute('divengine_database.evolution', ['schema_database' => $database->id()]));
      }
    }
    if ($manage) {
      $buttons[] = $this->button($this->t('Import snapshot'), Url::fromRoute('divengine_database.import', ['schema_database' => $database->id()]), !$latest);
      $buttons[] = $this->button($this->t('Edit'), $database->toUrl('edit-form'));
    }
    $build['status'] = $this->statusBar((string) $database->get('code')->value, [
      (string) $this->t('Development') => $database->getDevelopment()?->label(),
      (string) $this->t('Identifier') => $database->getIdentifier(),
      (string) $this->t('Engine') => $database->get('engine')->value,
      (string) $this->t('Latest snapshot') => $latest?->label(),
    ], $buttons);
    if ($description = $database->get('description')->value) {
      $build['description'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $description, '#attributes' => ['class' => ['dv-intro']], '#weight' => -95];
    }

    if ($latest) {
      $warnings = $latest->count('finding_warning_count') + $latest->count('finding_error_count');
      $build['cards'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-cards']],
        '#weight' => -90,
        'tables' => $this->card($this->t('Tables'), (string) $latest->count('table_count'), 'info', $latest->count('view_count') ? (string) $this->formatPlural($latest->count('view_count'), '+ 1 view', '+ @count views') : ''),
        'columns' => $this->card($this->t('Columns'), number_format($latest->count('column_count')), 'neutral'),
        'relationships' => $this->card($this->t('Foreign keys'), (string) $latest->count('real_fk_count'), 'neutral', $latest->count('implied_fk_count') ? (string) $this->t('+ @n implied', ['@n' => $latest->count('implied_fk_count')]) : ''),
        'findings' => $this->card($this->t('Findings'), (string) $latest->count('finding_count'), $warnings ? 'warning' : 'success', $warnings ? (string) $this->formatPlural($warnings, '1 warning', '@count warnings') : ''),
        'snapshots' => $this->card($this->t('Snapshots'), (string) count($ready), 'neutral', (string) $this->t('since @date', ['@date' => \Drupal::service('date.formatter')->format((int) end($ready)->get('captured_at')->value, 'custom', 'Y-m-d')])),
      ];
    }
    else {
      $build['empty'] = $this->section($this->t('Feed it a snapshot'), 0, $this->howTo($database), NULL, $this->t('Any analyzer can send the schema: SchemaSpy works out of the box.')) + ['#weight' => -90];
    }

    $rows = [];
    foreach ($history as $snapshot) {
      $actions = [];
      if ($snapshot->isReady()) {
        $actions[] = $this->button($this->t('Explore'), Url::fromRoute('divengine_database.explorer', ['schema_snapshot' => $snapshot->id()]));
        if ($previous = $snapshot->getPrevious()) {
          $actions[] = $this->button($this->t('Changes'), Url::fromRoute('divengine_database.evolution', ['schema_database' => $database->id()], ['query' => ['from' => $previous->id(), 'to' => $snapshot->id()]]));
        }
      }
      $rows[] = [
        ['data' => $snapshot->toLink()->toRenderable()],
        ['data' => $this->code($snapshot->get('source_revision')->value)],
        (string) $snapshot->get('source')->value,
        \Drupal::service('date.formatter')->format((int) $snapshot->get('captured_at')->value, 'short'),
        (string) ($snapshot->count('table_count') + $snapshot->count('view_count')),
        number_format($snapshot->count('column_count')),
        (string) ($snapshot->count('real_fk_count') + $snapshot->count('implied_fk_count')),
        ['data' => $this->badge((string) SchemaSnapshot::states()[$snapshot->get('state')->value], match ($snapshot->get('state')->value) { 'ready' => 'success', 'failed' => 'danger', default => 'warning' })],
        ['data' => $this->actions($actions) ?? []],
      ];
    }
    $build['snapshots'] = $this->section($this->t('Snapshots'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Snapshot'), $this->t('Revision'), $this->t('Analyzer'), $this->t('Captured'), $this->t('Tables'), $this->t('Columns'), $this->t('Relationships'), $this->t('State'), ''],
      '#rows' => $rows,
      '#empty' => $this->t('No snapshot yet.'),
    ], $manage ? $this->button($this->t('Import snapshot'), Url::fromRoute('divengine_database.import', ['schema_database' => $database->id()])) : NULL) + ['#weight' => -70];
    if ($latest) {
      $build['feed'] = [
        '#type' => 'details',
        '#title' => $this->t('How to send new snapshots'),
        '#weight' => -60,
        'content' => $this->howTo($database),
      ];
    }
    return $build;
  }

  /**
   * The ways an analyzer can feed this database.
   */
  protected function howTo(SchemaDatabase $database): array {
    $identifier = $database->getIdentifier();
    $endpoint = Url::fromRoute('divengine_database.api.import', [], ['absolute' => TRUE, 'query' => ['database' => $database->get('code')->value]])->toString();
    $lines = [
      '# ' . $this->t('From the command line (SchemaSpy XML or the Divengine contract):'),
      'vendor/bin/drush divengine:database-import ' . $identifier . '.xml --database=' . $database->get('code')->value . ' --revision=$(git rev-parse HEAD)',
      '',
      '# ' . $this->t('From CI or any analyzer, over HTTP (an account with "Import database snapshots"):'),
      'curl -u analyzer:PASSWORD -H "Content-Type: application/json" --data-binary @snapshot.json "' . $endpoint . '&revision=$GIT_SHA"',
    ];
    return [
      'text' => ['#markup' => '<p>' . $this->t('Snapshots name this database <code>@identifier</code>, or pass its code explicitly. Each import is checked, hashed and kept immutable; a schema equal to the latest one is reported as unchanged.', ['@identifier' => $identifier]) . '</p>'],
      'code' => ['#type' => 'html_tag', '#tag' => 'pre', '#value' => htmlspecialchars(implode("\n", $lines)), '#attributes' => ['class' => ['dv-db-sql']]],
    ];
  }

}
