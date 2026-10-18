<?php

declare(strict_types=1);

namespace Drupal\divengine_source;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_source\Entity\SourceRef;
use Drupal\divengine_source\Entity\SourceRepository;
use Drupal\divengine_source\Entity\SourceSnapshot;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page of a repository: its refs, their indexing and its history.
 */
class SourceViewBuilder extends EntityViewBuilder {

  protected SourceIndex $index;

  protected EntityTypeManagerInterface $entityTypeManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->index = $container->get('divengine_source.index');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $repository) {
      foreach (array_keys($repository->getFieldDefinitions()) as $field_name) {
        unset($build[$id][$field_name]);
      }
      $build[$id] += $this->repository($repository);
      $build[$id]['#attached']['library'][] = 'divengine_source/source';
      $build[$id]['#cache']['tags'][] = 'source_ref_list';
      $build[$id]['#cache']['tags'][] = 'source_snapshot_list';
    }
  }

  protected function repository(SourceRepository $repository): array {
    $refs = $this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $repository->id()]);
    uasort($refs, fn ($a, $b) => [!$a->get('is_default')->value, $a->get('name')->value] <=> [!$b->get('is_default')->value, $b->get('name')->value]);
    $default = $repository->getDefaultRef();
    $manage = $repository->access('update');

    $actions = [];
    if ($default?->getLastSnapshot()) {
      $actions[] = $this->button($this->t('Browse code'), Url::fromRoute('divengine_source.browse', ['source_repository' => $repository->id()]), TRUE);
    }
    if ($manage) {
      $actions[] = $this->button($this->t('Add ref'), Url::fromRoute('entity.source_ref.add_form', [], ['query' => ['repository' => $repository->id()]]));
      $actions[] = $this->button($this->t('Edit'), $repository->toUrl('edit-form'));
    }
    $facts = array_filter([
      (string) $this->t('Development') => $repository->get('development')->entity?->label(),
      (string) $this->t('Default ref') => $default?->label(),
      (string) $this->t('Remote') => $repository->get('url')->value,
      (string) $this->t('Local clone') => $manage ? $repository->get('path')->value : NULL,
    ]);
    $build['status'] = [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => (string) $repository->get('code')->value, 'facts' => array_map(fn ($label, $value) => ['label' => $label, 'value' => (string) $value], array_keys($facts), $facts)],
      '#slots' => array_filter(['actions' => $actions ? ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'actions' => $actions] : NULL]),
      '#weight' => -100,
    ];

    $snapshots = $this->entityTypeManager->getStorage('source_snapshot');
    $ids = $snapshots->getQuery()->accessCheck(FALSE)->condition('repository', $repository->id())->sort('indexed', 'DESC')->sort('id', 'DESC')->range(0, 15)->execute();
    $history = $snapshots->loadMultiple($ids);
    $build['cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-cards']],
      'refs' => $this->card($this->t('Refs'), (string) count($refs), 'info'),
      'files' => $this->card($this->t('Files'), $default ? (string) $this->index->count($default) : '—', 'neutral', $default ? (string) $this->t('in @ref', ['@ref' => $default->label()]) : ''),
      'snapshots' => $this->card($this->t('Snapshots'), (string) $snapshots->getQuery()->accessCheck(FALSE)->condition('repository', $repository->id())->count()->execute(), 'neutral'),
      'indexed' => $this->card($this->t('Last indexed'), ($last = max(array_map(fn ($ref) => (int) $ref->get('last_indexed')->value, $refs) ?: [0])) ? \Drupal::service('date.formatter')->format($last, 'short') : (string) $this->t('Never'), $last ? 'success' : 'warning'),
      '#weight' => -90,
    ];
    if ($description = $repository->get('description')->value) {
      $build['description'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $description, '#attributes' => ['class' => ['dv-intro']], '#weight' => -85];
    }

    $rows = [];
    foreach ($refs as $ref) {
      $snapshot = $ref->getLastSnapshot();
      $links = [];
      if ($snapshot) {
        $links[] = $this->button($this->t('Browse'), Url::fromRoute('divengine_source.browse', ['source_repository' => $repository->id()], ['query' => ['ref' => $ref->id()]]));
      }
      if ($manage) {
        $links[] = $this->button($this->t('Index now'), Url::fromRoute('divengine_source.index_ref', ['source_ref' => $ref->id()]), !$snapshot);
        $links[] = $this->button($this->t('Edit'), $ref->toUrl('edit-form'));
      }
      $rows[] = [
        ['data' => ['name' => ['#plain_text' => $ref->label()], 'default' => $ref->get('is_default')->value ? $this->badge($this->t('default'), 'info') : []]],
        ['data' => ['#markup' => '<code>' . htmlspecialchars((string) $ref->get('name')->value) . '</code>']],
        ['data' => ['#plain_text' => (string) (SourceRef::kinds()[$ref->get('kind')->value] ?? '')]],
        ['data' => $snapshot ? ['#markup' => '<code>' . $snapshot->label() . '</code>'] : ['#plain_text' => (string) $this->t('Not indexed')]],
        ['data' => ['#plain_text' => $snapshot ? (string) $this->index->count($ref) : '']],
        ['data' => ['#plain_text' => $ref->get('last_indexed')->value ? \Drupal::service('date.formatter')->format((int) $ref->get('last_indexed')->value, 'short') : '']],
        ['data' => $ref->get('status')->value ? [] : $this->badge($this->t('archived'), 'neutral')],
        ['data' => ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'links' => $links]],
      ];
    }
    $build['refs'] = $this->section($this->t('Refs'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Ref'), $this->t('Git name'), $this->t('Kind'), $this->t('Indexed commit'), $this->t('Files'), $this->t('Indexed on'), '', ''],
      '#rows' => $rows,
      '#empty' => $this->t('No ref yet. Add the branches and tags you want to browse (e.g. main), then index them.'),
    ], $manage ? $this->button($this->t('Add ref'), Url::fromRoute('entity.source_ref.add_form', [], ['query' => ['repository' => $repository->id()]])) : NULL) + ['#weight' => -70];

    $rows = [];
    foreach ($history as $snapshot) {
      $rows[] = [
        ['data' => ['#plain_text' => (string) $snapshot->get('ref')->entity?->label()]],
        ['data' => ['#markup' => '<code>' . $snapshot->label() . '</code>']],
        ['data' => $this->badge((string) SourceSnapshot::states()[$snapshot->get('state')->value], match ($snapshot->get('state')->value) { 'ready' => 'success', 'failed' => 'danger', default => 'warning' })],
        ['data' => ['#plain_text' => (string) $snapshot->get('file_count')->value]],
        ['data' => ['#plain_text' => sprintf('+%d ~%d ↺%d −%d', $snapshot->get('added_count')->value, $snapshot->get('changed_count')->value, $snapshot->get('reactivated_count')->value, $snapshot->get('missing_count')->value)]],
        ['data' => ['#plain_text' => $snapshot->get('indexed')->value ? \Drupal::service('date.formatter')->format((int) $snapshot->get('indexed')->value, 'short') : '']],
        ['data' => ['#plain_text' => (string) $snapshot->get('error')->value]],
      ];
    }
    $build['history'] = $this->section($this->t('Indexing history'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Ref'), $this->t('Commit'), $this->t('State'), $this->t('Files'), $this->t('Added · changed · back · gone'), $this->t('On'), $this->t('Error')],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing indexed yet.'),
    ]) + ['#weight' => -60];
    return $build;
  }

  protected function card($label, string $value, string $tone, string $hint = ''): array {
    return ['#type' => 'component', '#component' => 'divengine_core:stat-card', '#props' => array_filter(['label' => (string) $label, 'value' => $value, 'tone' => $tone, 'hint' => $hint], fn ($v) => $v !== '')];
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function badge($label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => (string) $label, 'tone' => $tone]];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}
