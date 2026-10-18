<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_core\PageElementsTrait;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Drupal\divengine_process\Entity\DevelopmentInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Browsable documentation, using Studio's existing page components. */
class CodeDocsController extends ControllerBase {

  use PageElementsTrait;

  protected function page(): array {
    return ['#attached' => ['library' => ['divengine_codedocs/docs']], '#cache' => ['max-age' => 0]];
  }

  public function sets(?DevelopmentInterface $development = NULL): array {
    $storage = $this->entityTypeManager()->getStorage('codedoc_set');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('name');
    if ($development) {
      $query->condition('development', $development->id());
    }
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $set) {
      if ($set->access('view')) {
        $latest = $set->getLatestSnapshot();
        $rows[] = [['data' => $set->toLink()->toRenderable()], $set->getIdentifier(), $set->get('language')->value ?? '', ['data' => $latest ? $latest->toLink()->toRenderable() : ['#plain_text' => (string) $this->t('No snapshots yet')]]];
      }
    }
    $add = $this->entityTypeManager()->getAccessControlHandler('codedoc_set')->createAccess()
      ? $this->button($this->t('Register documentation'), Url::fromRoute('entity.codedoc_set.add_form', [], ['query' => $development ? ['development' => $development->id()] : []]), TRUE) : NULL;
    return $this->page() + ['list' => $this->section($this->t('Code docs'), count($rows), ['#type' => 'table', '#header' => [$this->t('Documentation'), $this->t('Identifier'), $this->t('Language'), $this->t('Latest snapshot')], '#rows' => $rows, '#empty' => $this->t('Register a codebase and publish its extracted documentation.')], $add)];
  }

  public function development(DevelopmentInterface $development): array {
    return $this->sets($development);
  }

  public function overview(CodeDocSet $set): array {
    $build = $this->page();
    $buttons = [];
    if ($set->access('update')) {
      $buttons[] = $this->button($this->t('Import snapshot'), Url::fromRoute('divengine_codedocs.import', ['codedoc_set' => $set->id()]), TRUE);
      $buttons[] = $this->button($this->t('Edit'), $set->toUrl('edit-form'));
    }
    $build['status'] = $this->statusBar((string) $set->get('code')->value, [(string) $this->t('Development') => $set->getDevelopment()?->label(), (string) $this->t('Identifier') => $set->getIdentifier(), (string) $this->t('Language') => $set->get('language')->value], $buttons);
    $build['description'] = ['#plain_text' => (string) $set->get('description')->value];
    $storage = $this->entityTypeManager()->getStorage('codedoc_snapshot');
    $items = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('doc_set', $set->id())->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 100)->execute());
    $rows = [];
    foreach ($items as $snapshot) {
      if ($snapshot->access('view')) {
        $rows[] = [['data' => $snapshot->toLink()->toRenderable()], (string) $snapshot->get('branch')->value, (string) $snapshot->get('commit_sha')->value, gmdate('Y-m-d H:i', (int) $snapshot->get('captured_at')->value) . ' UTC', $snapshot->count('symbol_count') + $snapshot->count('member_count'), $snapshot->coverage() === NULL ? '—' : $snapshot->coverage() . '%'];
      }
    }
    $build['history'] = $this->section($this->t('Snapshots'), count($rows), ['#type' => 'table', '#header' => [$this->t('Snapshot'), $this->t('Branch'), $this->t('Commit'), $this->t('Captured'), $this->t('Symbols'), $this->t('Documented API')], '#rows' => $rows, '#empty' => $this->t('Import a JSON file or publish from an extractor to start the history.')]);
    return $build;
  }

  public function snapshot(CodeDocSnapshot $snapshot): array {
    if (!$snapshot->isReady()) {
      throw new NotFoundHttpException();
    }
    $sid = (int) $snapshot->id();
    $index = \Drupal::service('divengine_codedocs.index');
    $build = $this->page();
    $build['status'] = $this->statusBar($snapshot->label(), [(string) $this->t('Language') => $snapshot->get('language')->value, (string) $this->t('Branch') => $snapshot->get('branch')->value, (string) $this->t('Commit') => $snapshot->get('commit_sha')->value], [$this->button($this->t('Documentation set'), $snapshot->getDocSet()->toUrl())]);
    $cards = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach (['unit_count', 'symbol_count', 'member_count', 'ref_count', 'endpoint_count'] as $counter) {
      $cards[$counter] = $this->card($this->t(CodeDocSnapshot::COUNTERS[$counter]), (string) $snapshot->count($counter));
    }
    $cards['coverage'] = $this->card($this->t('Documented API'), $snapshot->coverage() === NULL ? '—' : $snapshot->coverage() . '%');
    $build['cards'] = $cards;
    $q = trim((string) \Drupal::request()->query->get('q', ''));
    $offset = max(0, \Drupal::request()->query->getInt('offset'));
    $build['search'] = ['#type' => 'html_tag', '#tag' => 'form', '#attributes' => ['method' => 'get'], 'q' => ['#type' => 'html_tag', '#tag' => 'input', '#attributes' => ['type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => $this->t('Search symbols'), 'aria-label' => $this->t('Search symbols')]], 'submit' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Search'), '#attributes' => ['type' => 'submit']]];
    $symbols = $index->search($sid, $q, $offset);
    $build['symbols'] = $this->section($this->t('Symbols'), 0, $this->symbolTable($sid, $symbols));
    $buttons = [];
    if ($offset) {
      $buttons[] = $this->button($this->t('Previous'), $snapshot->toUrl('canonical', ['query' => ['q' => $q, 'offset' => max(0, $offset - 100)]]));
    }
    if (count($symbols) === 100) {
      $buttons[] = $this->button($this->t('Next'), $snapshot->toUrl('canonical', ['query' => ['q' => $q, 'offset' => $offset + 100]]));
    }
    if ($buttons) {
      $build['pages'] = $this->actions($buttons);
    }
    $units = $index->rows('unit', $sid, [], 200);
    $build['units'] = $this->section($this->t('Units (first 200)'), count($units), ['#type' => 'table', '#header' => [$this->t('Unit'), $this->t('Kind'), $this->t('Path'), $this->t('Summary')], '#rows' => array_map(fn ($u) => [$u['name'], $u['kind'], $u['path'] ?? '', $u['doc_summary'] ?? ''], $units)]);
    $endpoints = $index->rows('endpoint', $sid, [], 200);
    $build['endpoints'] = $this->section($this->t('Routes and HTTP calls (first 200)'), count($endpoints), ['#type' => 'table', '#header' => [$this->t('Kind'), $this->t('Method'), $this->t('Path')], '#rows' => array_map(fn ($e) => [$e['kind'], $e['method'] ?? '', $e['full_path'] ?? $e['path'] ?? $e['raw'] ?? ''], $endpoints), '#empty' => $this->t('No endpoints extracted.')]);
    $previous = $snapshot->getPrevious();
    if ($previous && $previous->access('view')) {
      $rows = [];
      foreach ($index->diff((int) $previous->id(), $sid) as $kind => $changes) {
        foreach ($changes as $change => $keys) {
          foreach ($keys as $key) {
            $rows[] = [$kind, $change, $key];
          }
        }
      }
      $build['diff'] = $this->section($this->t('Changes since @previous', ['@previous' => $previous->label()]), count($rows), ['#type' => 'table', '#header' => [$this->t('Kind'), $this->t('Change'), $this->t('Key')], '#rows' => array_slice($rows, 0, 500), '#empty' => $this->t('No documentation changes.')]);
    }
    return $build;
  }

  protected function symbolTable(int $sid, array $symbols): array {
    return ['#type' => 'table', '#header' => [$this->t('Symbol'), $this->t('Kind'), $this->t('Signature'), $this->t('Summary')], '#rows' => array_map(fn ($s) => [['data' => Link::fromTextAndUrl($s['name'], Url::fromRoute('divengine_codedocs.symbol', ['codedoc_snapshot' => $sid, 'symbol' => $s['id']]))->toRenderable()], $s['kind'], ['data' => $this->code($s['signature'])], $s['doc_summary'] ?? ''], $symbols), '#empty' => $this->t('No symbols found.')];
  }

  public function symbol(CodeDocSnapshot $codedoc_snapshot, int $symbol): array {
    if (!$codedoc_snapshot->isReady()) {
      throw new NotFoundHttpException();
    }
    $sid = (int) $codedoc_snapshot->id();
    $s = \Drupal::service('divengine_codedocs.index')->symbol($sid, $symbol) ?? throw new NotFoundHttpException();
    $build = $this->page();
    $build['#title'] = $s['name'];
    $buttons = [$this->button($this->t('Snapshot'), $codedoc_snapshot->toUrl())];
    if ($url = $this->sourceUrl($codedoc_snapshot, $s)) {
      $buttons[] = $this->button($this->t('View source at this commit'), $url, TRUE);
    }
    $build['status'] = $this->statusBar($s['symbol_key'], [(string) $this->t('Kind') => $s['kind'], (string) $this->t('Visibility') => $s['visibility'], (string) $this->t('File') => $s['file'], (string) $this->t('Line') => $s['line']], $buttons);
    $build['signature'] = ['#type' => 'html_tag', '#tag' => 'pre', 'text' => ['#plain_text' => $s['signature'] ?? $s['name']]];
    $build['doc'] = ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['dv-codedocs-description']], 'summary' => ['#plain_text' => (string) $s['doc_summary']], 'body' => ['#plain_text' => "\n" . $s['doc_description']]];
    foreach (['params' => ['name', 'kind', 'type_text', 'doc'], 'tags' => ['kind', 'name', 'text']] as $table => $columns) {
      $build[$table] = $this->section($this->t(ucfirst($table)), count($s[$table]), ['#type' => 'table', '#header' => $columns, '#rows' => array_map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', $columns), $s[$table])]);
    }
    $build['members'] = $this->section($this->t('Members (first 500)'), count($s['members']), $this->symbolTable($sid, $s['members']));
    foreach (['refs' => 'References', 'incoming' => 'Referenced by'] as $field => $title) {
      $rows = [];
      foreach ($s[$field] as $ref) {
        $target = $field === 'incoming' ? $ref['symbol_id'] : $ref['target_symbol_id'];
        $label = $field === 'incoming' ? ($ref['source_key'] ?? ('unit #' . $ref['unit_id'])) : $ref['target_key'];
        $rows[] = [$ref['kind'], ['data' => $target ? Link::fromTextAndUrl($label, Url::fromRoute('divengine_codedocs.symbol', ['codedoc_snapshot' => $sid, 'symbol' => $target]))->toRenderable() : ['#plain_text' => $label]], $ref['count']];
      }
      $build[$field] = $this->section($this->t($title . ' (first 500)'), count($rows), ['#type' => 'table', '#header' => [$this->t('Kind'), $this->t('Symbol'), $this->t('Count')], '#rows' => $rows]);
    }
    $build['extra'] = ['#type' => 'details', '#title' => $this->t('Extractor details'), 'json' => ['#type' => 'html_tag', '#tag' => 'pre', 'text' => ['#plain_text' => json_encode(json_decode($s['extra'], TRUE), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
    return $build;
  }

  protected function sourceUrl(CodeDocSnapshot $snapshot, array $symbol): ?Url {
    $set = $snapshot->getDocSet();
    $repository = $set->get('repository')->entity;
    if (!$repository || !$repository->access('view') || !$symbol['file'] || !$snapshot->get('commit_sha')->value) {
      return NULL;
    }
    $refs = $this->entityTypeManager()->getStorage('source_ref')->loadByProperties(['repository' => $repository->id(), 'status' => 1]);
    foreach ($refs as $ref) {
      if ($ref->getLastSnapshot()?->getCommit() === $snapshot->get('commit_sha')->value) {
        return Url::fromRoute('divengine_source.browse', ['source_repository' => $repository->id()], ['query' => ['ref' => $ref->id(), 'path' => trim($set->get('source_root')->value . '/' . $symbol['file'], '/')]]);
      }
    }
    return NULL;
  }

}
