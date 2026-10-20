<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\divengine_codedocs\Entity\CodeDocSet;
use Drupal\divengine_codedocs\Entity\CodeDocSnapshot;
use Drupal\divengine_core\PageElementsTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The documentation browser: the page of a set, of a snapshot (a tree of
 * units next to a unit, a search or an overview) and of a symbol.
 *
 * Extracted text is rendered as plain text, never as markup.
 */
class CodeDocsPages {

  use PageElementsTrait;
  use StringTranslationTrait;

  public function __construct(
    protected CodeDocIndex $index,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The page of a documentation set: what it documents and its history.
   */
  public function set(CodeDocSet $set): array {
    $buttons = [];
    $latest = $set->getLatestSnapshot();
    if ($latest) {
      $buttons[] = $this->button($this->t('Browse documentation'), $latest->toUrl(), TRUE);
    }
    if ($set->access('update')) {
      $buttons[] = $this->button($this->t('Import snapshot'), Url::fromRoute('divengine_codedocs.import', ['codedoc_set' => $set->id()]), !$latest);
      $buttons[] = $this->button($this->t('Edit'), $set->toUrl('edit-form'));
    }
    $build = $this->page();
    $build['status'] = $this->statusBar((string) $set->get('code')->value, [
      (string) $this->t('Development') => $set->getDevelopment()?->label(),
      (string) $this->t('Identifier') => $set->getIdentifier(),
      (string) $this->t('Language') => $set->get('language')->value,
      (string) $this->t('Repository') => $set->get('repository')->entity?->label(),
    ], $buttons);
    if ($description = $set->get('description')->value) {
      $build['description'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => htmlspecialchars($description), '#attributes' => ['class' => ['dv-intro']], '#weight' => -90];
    }
    if ($latest) {
      $build['cards'] = $this->cards($latest) + ['#weight' => -80];
    }
    $storage = $this->entityTypeManager->getStorage('codedoc_snapshot');
    $rows = [];
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('doc_set', $set->id())->condition('state', 'ready')->sort('captured_at', 'DESC')->sort('id', 'DESC')->range(0, 100)->execute()) as $snapshot) {
      $rows[] = [
        ['data' => $snapshot->toLink()->toRenderable()],
        ['data' => $this->code((string) $snapshot->get('branch')->value)],
        ['data' => $this->code(substr((string) $snapshot->get('commit_sha')->value, 0, 12))],
        $this->dateFormatter->format((int) $snapshot->get('captured_at')->value, 'short'),
        (string) ($snapshot->count('symbol_count') + $snapshot->count('member_count')),
        $snapshot->coverage() === NULL ? '—' : $snapshot->coverage() . '%',
      ];
    }
    $build['history'] = $this->section($this->t('Snapshots'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Snapshot'), $this->t('Branch'), $this->t('Commit'), $this->t('Captured'), $this->t('Symbols'), $this->t('Documented API')],
      '#rows' => $rows,
      '#empty' => $this->t('Import a JSON file or publish from an extractor to start the history.'),
    ]) + ['#weight' => -70];
    $build['#cache']['tags'][] = 'codedoc_snapshot_list';
    return $build;
  }

  /**
   * The page of a snapshot: the unit tree and, next to it, a unit, the
   * results of a search or an overview.
   */
  public function snapshot(CodeDocSnapshot $snapshot): array {
    if (!$snapshot->isReady()) {
      throw new NotFoundHttpException();
    }
    $sid = (int) $snapshot->id();
    $query = $this->requestStack->getCurrentRequest()->query;
    $text = trim((string) $query->get('q', ''));
    $unit_id = $query->getInt('unit');
    $units = $this->index->units($sid);
    $build = $this->page();
    $build['status'] = $this->statusBar($snapshot->label(), [
      (string) $this->t('Language') => $snapshot->get('language')->value,
      (string) $this->t('Branch') => $snapshot->get('branch')->value,
      (string) $this->t('Commit') => substr((string) $snapshot->get('commit_sha')->value, 0, 12),
      (string) $this->t('Extractor') => $snapshot->get('producer')->value ?: $snapshot->get('format')->value,
      (string) $this->t('Captured') => $this->dateFormatter->format((int) $snapshot->get('captured_at')->value, 'short'),
    ], [$this->button($this->t('Documentation set'), $snapshot->getDocSet()->toUrl())]);
    if ($text === '' && !$unit_id) {
      $build['cards'] = $this->cards($snapshot);
    }
    if ($text !== '') {
      $main = $this->search($snapshot, $text, $units);
    }
    elseif ($unit_id && isset($units[$unit_id])) {
      $main = $this->unit($snapshot, $unit_id, $units);
    }
    elseif ($unit_id) {
      throw new NotFoundHttpException();
    }
    else {
      $main = $this->summary($snapshot, $units);
    }
    $build['browser'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-docs']],
      'tree' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-docs__tree']],
        'search' => $this->searchForm($snapshot, $text),
        'units' => $this->tree($snapshot, $units, $unit_id),
      ],
      'main' => ['#type' => 'container', '#attributes' => ['class' => ['dv-docs__main']]] + $main,
    ];
    $build['#cache']['contexts'] = ['url.query_args:q', 'url.query_args:unit', 'user.permissions'];
    return $build;
  }

  /**
   * The page of a symbol.
   */
  public function symbol(CodeDocSnapshot $snapshot, int $id): array {
    if (!$snapshot->isReady()) {
      throw new NotFoundHttpException();
    }
    $sid = (int) $snapshot->id();
    $s = $this->index->symbol($sid, $id) ?? throw new NotFoundHttpException();
    $unit = $this->index->rows('unit', $sid, ['id' => $s['unit_id']], 1)[0] ?? NULL;
    $build = $this->page();
    $build['#title'] = $s['name'];

    $crumbs = [Link::fromTextAndUrl($unit['name'] ?? (string) $this->t('Unit'), $snapshot->toUrl('canonical', ['query' => ['unit' => $s['unit_id']]]))->toRenderable()];
    foreach ($this->index->ancestors($sid, $id) as $ancestor) {
      $crumbs[] = (int) $ancestor['id'] === $id ? ['#plain_text' => $ancestor['name']] : $this->symbolLink($sid, (int) $ancestor['id'], $ancestor['name']);
    }
    $build['trail'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-docs__trail']], 'items' => $this->join($crumbs, ' › '), '#weight' => -110];

    $buttons = [$this->button($this->t('Unit'), $snapshot->toUrl('canonical', ['query' => ['unit' => $s['unit_id']]]))];
    if ($url = $this->sourceUrl($snapshot, $s)) {
      $buttons[] = $this->button($this->t('View source'), $url, TRUE);
    }
    $build['status'] = $this->statusBar((string) $s['symbol_key'], [
      (string) $this->t('Kind') => $this->humanize((string) $s['kind']),
      (string) $this->t('Visibility') => $s['visibility'],
      (string) $this->t('Modifiers') => str_replace(',', ' ', (string) $s['modifiers']),
      (string) $this->t('File') => $s['file'] ? $s['file'] . ($s['line'] ? ':' . $s['line'] : '') : NULL,
    ], $buttons);
    $badges = array_filter([
      $s['deprecated'] ? $this->badge($this->t('deprecated'), 'danger') : NULL,
      $s['generated'] ? $this->badge($this->t('generated'), 'neutral') : NULL,
      $s['exported'] ? $this->badge($this->t('exported'), 'info') : NULL,
    ]);
    if ($badges) {
      $build['badges'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-docs__badges']], 'items' => $badges, '#weight' => -95];
    }
    if ($s['signature']) {
      $build['signature'] = ['#type' => 'html_tag', '#tag' => 'pre', '#attributes' => ['class' => ['dv-docs__signature']], 'text' => ['#plain_text' => $s['signature']], '#weight' => -90];
    }
    $build['doc'] = $this->doc($sid, $s) + ['#weight' => -85];
    // An override without documentation takes its parameters' descriptions
    // from what it overrides, by position.
    if (!$s['documented'] && $s['params'] && ($inherited = $this->index->inheritedDoc($sid, $id))) {
      $docs = array_column($this->index->rows('param', $sid, ['symbol_id' => $inherited['id']], 200), 'doc', 'position');
      foreach ($s['params'] as &$param) {
        $param['doc'] = $param['doc'] ?: ($docs[$param['position']] ?? '');
      }
      unset($param);
    }

    if ($s['params']) {
      $kinds = array_unique(array_column($s['params'], 'kind'));
      $rows = array_map(fn ($p) => array_merge(
        [['data' => $this->code((string) $p['name'])], ['data' => $this->code((string) $p['type_text'])]],
        count($kinds) > 1 ? [$this->humanize((string) $p['kind'])] : [],
        [trim(($p['optional'] ? '(' . $this->t('optional') . ') ' : '') . ($p['rest'] ? '(…) ' : '') . ($p['default_value'] !== NULL && $p['default_value'] !== '' ? '= ' . $p['default_value'] . ' ' : '') . $p['doc'])],
      ), $s['params']);
      $build['params'] = $this->section($this->t('Parameters'), count($rows), [
        '#type' => 'table',
        '#header' => array_merge([$this->t('Name'), $this->t('Type')], count($kinds) > 1 ? [$this->t('Kind')] : [], [$this->t('Description')]),
        '#rows' => $rows,
      ]) + ['#weight' => -80];
    }
    if ($s['tags']) {
      $build['tags'] = $this->section($this->t('Doc tags'), count($s['tags']), [
        '#type' => 'table',
        '#header' => [$this->t('Tag'), $this->t('About'), $this->t('Text')],
        '#rows' => array_map(fn ($t) => [['data' => $this->code('@' . $t['kind'])], (string) $t['name'], (string) $t['text']], $s['tags']),
      ]) + ['#weight' => -75];
    }
    if ($s['members']) {
      $build['members'] = $this->section($this->t('Members'), count($s['members']), $this->symbolTable($sid, $s['members'])) + ['#weight' => -70];
    }
    if ($s['refs']) {
      $build['refs'] = $this->section($this->t('Uses'), count($s['refs']), $this->refTable($sid, $s['refs'], FALSE), NULL, $this->t('Types, symbols and modules this one refers to.')) + ['#weight' => -65];
    }
    if ($s['incoming']) {
      $build['incoming'] = $this->section($this->t('Used by'), count($s['incoming']), $this->refTable($sid, $s['incoming'], TRUE)) + ['#weight' => -60];
    }
    $endpoints = $this->index->rows('endpoint', $sid, ['symbol_id' => $id], 200);
    if ($endpoints) {
      $build['endpoints'] = $this->section($this->t('Routes and HTTP calls'), count($endpoints), $this->endpointTable($sid, $endpoints)) + ['#weight' => -55];
    }
    $extra = json_decode((string) $s['extra'], TRUE);
    if ($extra) {
      $build['extra'] = ['#type' => 'details', '#title' => $this->t('Extractor details'), '#weight' => -50, 'json' => ['#type' => 'html_tag', '#tag' => 'pre', 'text' => ['#plain_text' => json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
    }
    // The link to the source depends on the user's access to the repository.
    $build['#cache']['contexts'] = ['user'];
    $build['#cache']['tags'][] = 'source_ref_list';
    return $build;
  }

  protected function page(): array {
    return ['#attached' => ['library' => ['divengine_codedocs/docs']]];
  }

  protected function cards(CodeDocSnapshot $snapshot): array {
    $cards = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach (['unit_count' => $this->t('Units'), 'symbol_count' => $this->t('Top-level symbols'), 'member_count' => $this->t('Members'), 'endpoint_count' => $this->t('Routes & HTTP calls')] as $counter => $label) {
      $cards[$counter] = $this->card($label, number_format($snapshot->count($counter)));
    }
    $coverage = $snapshot->coverage();
    $cards['coverage'] = $this->card($this->t('Documented API'), $coverage === NULL ? '—' : $coverage . '%', $coverage === NULL ? 'neutral' : ($coverage >= 80 ? 'success' : ($coverage >= 50 ? 'warning' : 'danger')), (string) $this->t('@documented of @total public symbols', ['@documented' => $snapshot->count('documented_count'), '@total' => $snapshot->count('documentable_count')]));
    return $cards;
  }

  protected function searchForm(CodeDocSnapshot $snapshot, string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => ['method' => 'get', 'action' => $snapshot->toUrl()->toString(), 'class' => ['dv-docs__search'], 'role' => 'search'],
      'q' => ['#type' => 'html_tag', '#tag' => 'input', '#attributes' => ['type' => 'search', 'name' => 'q', 'value' => $text, 'placeholder' => (string) $this->t('Search symbols…'), 'aria-label' => (string) $this->t('Search symbols'), 'class' => ['form-text']]],
    ];
  }

  /**
   * Units as a tree: units other units name as parent (packages,
   * namespaces) hold them; the rest are grouped by folder.
   */
  protected function tree(CodeDocSnapshot $snapshot, array $units, int $current): array {
    $keys = array_column($units, 'id', 'unit_key');
    $children = [];
    $top = [];
    foreach ($units as $id => $unit) {
      if ($unit['parent_key'] !== NULL && $unit['parent_key'] !== '' && isset($keys[$unit['parent_key']])) {
        $children[$keys[$unit['parent_key']]][] = $id;
      }
      else {
        $top[$unit['path'] ? (dirname($unit['path']) === '.' ? '' : dirname($unit['path'])) : ''][] = $id;
      }
    }
    ksort($top, SORT_STRING);
    $item = function (int $id) use (&$item, $units, $children, $snapshot, $current): array {
      $unit = $units[$id];
      $label = $unit['path'] ? basename($unit['path']) : $unit['name'];
      $link = Link::fromTextAndUrl($label, $snapshot->toUrl('canonical', ['query' => ['unit' => $id]]))->toRenderable();
      $link['#attributes']['class'][] = 'dv-docs__unit';
      if ($id === $current) {
        $link['#attributes']['aria-current'] = 'page';
      }
      if ($unit['is_test']) {
        $link['#attributes']['class'][] = 'is-test';
      }
      $element = ['link' => $link];
      if (!empty($children[$id])) {
        $element['children'] = ['#theme' => 'item_list', '#items' => array_map($item, $children[$id])];
      }
      return $element;
    };
    $groups = [];
    foreach ($top as $folder => $ids) {
      usort($ids, fn ($a, $b) => strcmp($units[$a]['name'], $units[$b]['name']));
      $list = ['#theme' => 'item_list', '#items' => array_map($item, $ids)];
      $groups[] = $folder === '' ? $list : [
        '#type' => 'html_tag',
        '#tag' => 'details',
        '#attributes' => ['open' => 'open', 'class' => ['dv-docs__folder']],
        'summary' => ['#type' => 'html_tag', '#tag' => 'summary', '#value' => htmlspecialchars($folder)],
        'list' => $list,
      ];
    }
    return ['#type' => 'container', '#attributes' => ['class' => ['dv-docs__units']]] + $groups;
  }

  /**
   * The overview of a snapshot: what kinds of symbols, coverage by unit,
   * routes, and what changed since the previous snapshot.
   */
  protected function summary(CodeDocSnapshot $snapshot, array $units): array {
    $sid = (int) $snapshot->id();
    $build = [];
    $kinds = $snapshot->kinds();
    arsort($kinds);
    $build['kinds'] = $this->section($this->t('What is documented'), 0, [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-docs__badges']],
      'items' => array_map(fn ($kind, $count) => $this->badge($this->humanize((string) $kind) . ' · ' . $count, 'neutral'), array_keys($kinds), $kinds),
    ]);
    $rows = [];
    foreach ($units as $id => $unit) {
      if ($unit['documentable']) {
        $percent = round(100 * $unit['documented'] / $unit['documentable']);
        $rows[] = [
          ['data' => Link::fromTextAndUrl($unit['name'], $snapshot->toUrl('canonical', ['query' => ['unit' => $id]]))->toRenderable()],
          $unit['documented'] . ' / ' . $unit['documentable'],
          ['data' => $this->badge($percent . '%', $percent >= 80 ? 'success' : ($percent >= 50 ? 'warning' : 'danger'))],
        ];
      }
    }
    usort($rows, fn ($a, $b) => (int) $a[2]['data']['#props']['label'] <=> (int) $b[2]['data']['#props']['label']);
    $build['coverage'] = $this->section($this->t('Documentation coverage'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Unit'), $this->t('Documented'), ''],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing public to document.'),
    ], NULL, $this->t('Public or exported, handwritten symbols with documentation. Overrides inherit theirs and are not counted. Least documented first.'));
    $undocumented = $this->index->undocumented($sid, 500);
    if ($undocumented) {
      $build['undocumented'] = [
        '#type' => 'details',
        '#title' => $this->t('Undocumented public symbols (@count)', ['@count' => count($undocumented)]),
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Symbol'), $this->t('Kind'), $this->t('Unit')],
          '#rows' => array_map(fn ($s) => [['data' => $this->symbolLink($sid, (int) $s['id'], $s['name'])], $this->humanize((string) $s['kind']), $units[$s['unit_id']]['name'] ?? ''], $undocumented),
        ],
      ];
    }
    $endpoints = $this->index->rows('endpoint', $sid, [], 200);
    if ($endpoints) {
      $build['endpoints'] = $this->section($this->t('Routes and HTTP calls'), $snapshot->count('endpoint_count'), $this->endpointTable($sid, $endpoints));
    }
    $previous = $snapshot->getPrevious();
    if ($previous && $previous->access('view')) {
      $build['changes'] = $this->changes($previous, $snapshot);
    }
    return $build;
  }

  /**
   * What changed in the documentation since a previous snapshot.
   */
  protected function changes(CodeDocSnapshot $previous, CodeDocSnapshot $snapshot): array {
    $sid = (int) $snapshot->id();
    $diff = $this->index->diff((int) $previous->id(), $sid);
    $ids = $this->index->symbolIds($sid, array_merge($diff['symbol']['added'], $diff['symbol']['changed']));
    $rows = [];
    $tones = ['added' => 'success', 'changed' => 'warning', 'removed' => 'danger'];
    $names = ['added' => $this->t('added'), 'changed' => $this->t('changed'), 'removed' => $this->t('removed')];
    foreach (['symbol' => $this->t('Symbol'), 'unit' => $this->t('Unit')] as $type => $label) {
      foreach (['added', 'changed', 'removed'] as $change) {
        foreach ($diff[$type][$change] as $key) {
          $target = $type === 'symbol' && isset($ids[$key]) ? $this->symbolLink($sid, (int) $ids[$key], $key) : $this->code($key);
          $rows[] = [['data' => $this->badge($names[$change], $tones[$change])], (string) $label, ['data' => $target]];
        }
      }
    }
    return $this->section($this->t('Changes since @previous', ['@previous' => $previous->label()]), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Change'), $this->t('What'), $this->t('Key')],
      '#rows' => array_slice($rows, 0, 500),
      '#empty' => $this->t('No documentation changes: same symbols, same documentation.'),
    ], NULL, $this->t('Positions in files are not changes; signatures, documentation and relations are.'));
  }

  /**
   * A unit: its documentation, the units it holds, and its symbols with
   * their members.
   */
  protected function unit(CodeDocSnapshot $snapshot, int $id, array $units): array {
    $sid = (int) $snapshot->id();
    $unit = $this->index->rows('unit', $sid, ['id' => $id], 1)[0];
    $build['title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => htmlspecialchars($unit['name']), '#attributes' => ['class' => ['dv-docs__title']]];
    $facts = array_filter([$this->humanize((string) $unit['kind']), $unit['category'] && $unit['category'] !== 'other' ? $this->humanize((string) $unit['category']) : NULL, $unit['path'], $unit['lines'] ? $this->t('@n lines', ['@n' => $unit['lines']]) : NULL]);
    $build['facts'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => htmlspecialchars(implode(' · ', array_map('strval', $facts))), '#attributes' => ['class' => ['dv-docs__facts']]];
    $build['doc'] = $this->docText((string) $unit['doc_summary'], (string) $unit['doc_description']);
    $inside = array_filter($units, fn ($u) => $u['parent_key'] === $unit['unit_key']);
    if ($inside) {
      $build['units'] = $this->section($this->t('Contents'), count($inside), [
        '#type' => 'table',
        '#header' => [$this->t('Unit'), $this->t('Summary')],
        '#rows' => array_map(fn ($u) => [['data' => Link::fromTextAndUrl($u['name'], $snapshot->toUrl('canonical', ['query' => ['unit' => $u['id']]]))->toRenderable()], (string) $u['doc_summary']], $inside),
      ]);
    }
    $symbols = $this->index->unitSymbols($sid, $id);
    $by_parent = [];
    foreach ($symbols as $symbol) {
      $by_parent[(int) $symbol['parent_id']][] = $symbol;
    }
    foreach ($by_parent[0] ?? [] as $symbol) {
      $members = $by_parent[(int) $symbol['id']] ?? [];
      $build['symbol_' . $symbol['id']] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-docs__symbol'], 'id' => 'symbol-' . $symbol['id']],
        'head' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dv-docs__symbol-head']],
          'name' => $this->symbolLink($sid, (int) $symbol['id'], $symbol['name']),
          'kind' => $this->badge($this->humanize((string) $symbol['kind']), 'info'),
          'deprecated' => $symbol['deprecated'] ? $this->badge($this->t('deprecated'), 'danger') : [],
        ],
        'signature' => $symbol['signature'] ? ['#type' => 'html_tag', '#tag' => 'pre', '#attributes' => ['class' => ['dv-docs__signature']], 'text' => ['#plain_text' => $symbol['signature']]] : [],
        'doc' => $this->docText((string) $symbol['doc_summary'], ''),
        'members' => $members ? $this->symbolTable($sid, $members) : [],
      ];
    }
    if (!$symbols && !$inside) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('Nothing declared here.') . '</p>'];
    }
    $endpoints = $this->index->rows('endpoint', $sid, ['unit_id' => $id], 200);
    if ($endpoints) {
      $build['endpoints'] = $this->section($this->t('Routes and HTTP calls'), count($endpoints), $this->endpointTable($sid, $endpoints));
    }
    return $build;
  }

  protected function search(CodeDocSnapshot $snapshot, string $text, array $units): array {
    $sid = (int) $snapshot->id();
    $found = $this->index->search($sid, $text);
    return ['results' => $this->section($this->t('Symbols matching “@text”', ['@text' => $text]), count($found), [
      '#type' => 'table',
      '#header' => [$this->t('Symbol'), $this->t('Kind'), $this->t('Unit'), $this->t('Summary')],
      '#rows' => array_map(fn ($s) => [['data' => $this->symbolLink($sid, (int) $s['id'], $s['name'])], $this->humanize((string) $s['kind']), $units[$s['unit_id']]['name'] ?? '', (string) $s['doc_summary']], $found),
      '#empty' => $this->t('No symbol matches. Search by name, qualified name or key.'),
    ], NULL, count($found) === 100 ? $this->t('Showing the first 100.') : NULL)];
  }

  /**
   * The documentation of a symbol, or what it inherits.
   */
  protected function doc(int $sid, array $s): array {
    if ($s['documented']) {
      return $this->docText((string) $s['doc_summary'], (string) $s['doc_description']);
    }
    if ($inherited = $this->index->inheritedDoc($sid, (int) $s['id'])) {
      return [
        'note' => ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['dv-docs__inherited']], 'text' => ['#markup' => $this->t('Documentation inherited from') . ' '], 'link' => $this->symbolLink($sid, (int) $inherited['id'], $inherited['symbol_key'])],
        'text' => $this->docText((string) $inherited['doc_summary'], (string) $inherited['doc_description']),
      ];
    }
    return ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Not documented.'), '#attributes' => ['class' => ['dv-docs__missing']]];
  }

  protected function docText(string $summary, string $description): array {
    $text = trim($description) !== '' ? $description : $summary;
    return trim($text) === '' ? [] : ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['dv-codedocs-description']], 'text' => ['#plain_text' => trim($text)]];
  }

  protected function symbolTable(int $sid, array $symbols): array {
    return [
      '#type' => 'table',
      '#attributes' => ['class' => ['dv-docs__members']],
      '#header' => [$this->t('Member'), $this->t('Kind'), $this->t('Signature'), $this->t('Summary')],
      '#rows' => array_map(fn ($s) => [
        ['data' => $this->symbolLink($sid, (int) $s['id'], $s['name'])],
        $this->humanize((string) $s['kind']) . ($s['deprecated'] ? ' · ' . $this->t('deprecated') : ''),
        ['data' => $this->code((string) $s['signature'])],
        (string) $s['doc_summary'],
      ], $symbols),
    ];
  }

  protected function refTable(int $sid, array $refs, bool $incoming): array {
    $rows = [];
    foreach ($refs as $ref) {
      if ($incoming) {
        $target = $ref['symbol_id'] ? $this->symbolLink($sid, (int) $ref['symbol_id'], (string) ($ref['source_symbol_key'] ?? $ref['symbol_id'])) : ['#plain_text' => (string) ($ref['source_unit_name'] ?? $this->t('module'))];
      }
      else {
        $label = (string) ($ref['display'] ?: $ref['target_key']);
        $target = $ref['target_symbol_id'] ? $this->symbolLink($sid, (int) $ref['target_symbol_id'], $ref['target_key']) : ['#plain_text' => $ref['target_key'] . ($ref['external'] ? ' ↗' : '')];
        if ($label !== $ref['target_key'] && !$ref['target_symbol_id']) {
          $target = ['#plain_text' => $label . ($ref['external'] ? ' ↗' : '')];
        }
      }
      $rows[] = [$this->humanize((string) $ref['kind']), ['data' => $target], $ref['line'] ? (string) $ref['line'] : '', $ref['count'] > 1 ? (string) $ref['count'] : ''];
    }
    return ['#type' => 'table', '#header' => [$this->t('Relation'), $incoming ? $this->t('From') : $this->t('To'), $this->t('Line'), $this->t('Times')], '#rows' => $rows];
  }

  protected function endpointTable(int $sid, array $endpoints): array {
    return [
      '#type' => 'table',
      '#header' => [$this->t('Kind'), $this->t('Method'), $this->t('Path'), $this->t('In')],
      '#rows' => array_map(fn ($e) => [
        $this->humanize((string) $e['kind']),
        ['data' => $this->code((string) $e['method'])],
        ['data' => $this->code((string) ($e['full_path'] ?: $e['path'] ?: $e['raw']))],
        ['data' => $e['symbol_id'] ? $this->symbolLink($sid, (int) $e['symbol_id'], (string) $e['symbol_key']) : ['#plain_text' => (string) $e['symbol_key']]],
      ], $endpoints),
    ];
  }

  protected function symbolLink(int $sid, int $id, string $label): array {
    return Link::fromTextAndUrl($label, Url::fromRoute('divengine_codedocs.symbol', ['codedoc_snapshot' => $sid, 'symbol' => $id]))->toRenderable();
  }

  /**
   * "returnType", "type_param" and "annotation_type" read as words.
   */
  protected function humanize(string $kind): string {
    return mb_strtolower(trim((string) preg_replace(['/([a-z])([A-Z])/', '/[_\-]+/'], ['$1 $2', ' '], $kind)));
  }

  protected function join(array $items, string $separator): array {
    $out = [];
    foreach ($items as $i => $item) {
      if ($i) {
        $out[] = ['#plain_text' => $separator];
      }
      $out[] = $item;
    }
    return $out;
  }

  /**
   * The source of a symbol, at the documented commit, when a ref of the
   * linked repository was indexed at that commit.
   */
  protected function sourceUrl(CodeDocSnapshot $snapshot, array $symbol): ?Url {
    $set = $snapshot->getDocSet();
    $repository = $set->get('repository')->entity;
    $commit = (string) $snapshot->get('commit_sha')->value;
    if (!$repository || !$repository->access('view') || !$symbol['file'] || $commit === '') {
      return NULL;
    }
    foreach ($this->entityTypeManager->getStorage('source_ref')->loadByProperties(['repository' => $repository->id(), 'status' => 1]) as $ref) {
      if ($ref->getLastSnapshot()?->getCommit() === $commit) {
        return Url::fromRoute('divengine_source.browse', ['source_repository' => $repository->id()], [
          'query' => ['ref' => $ref->id(), 'path' => trim($set->get('source_root')->value . '/' . $symbol['file'], '/')],
          'fragment' => $symbol['line'] ? 'L' . $symbol['line'] : '',
        ]);
      }
    }
    return NULL;
  }

}
