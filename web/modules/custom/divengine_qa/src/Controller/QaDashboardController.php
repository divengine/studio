<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_qa\QaDashboard;
use Drupal\divengine_qa\QaVocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The QA dashboard and the filtered record lists it drills down to.
 */
class QaDashboardController extends ControllerBase {

  /**
   * Record lists of the drill-downs: entity type and allowed filters.
   */
  const BROWSE = [
    'plans' => 'qa_test_plan',
    'suites' => 'qa_test_suite',
    'cases' => 'qa_test_case',
    'steps' => 'qa_test_step',
    'runs' => 'qa_test_run',
    'executions' => 'qa_test_execution',
    'issues' => 'qa_issue',
  ];

  const COLORS = QaDashboard::RESULT_COLORS + ['draft' => '#64748b', 'ready' => '#22c55e', 'obsolete' => '#94a3b8'];

  protected QaDashboard $dashboard;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->dashboard = $container->get('divengine_qa.dashboard');
    return $instance;
  }

  public function page(Request $request): array {
    $development = $request->query->get('development') ?: NULL;
    $environment = $request->query->get('environment') ?: NULL;
    $data = $this->dashboard->build($development, $environment);
    $filters = array_filter(['development' => $development, 'environment' => $environment]);
    $browse = fn (string $list, array $extra = []) => Url::fromRoute('divengine_qa.browse', ['list' => $list], ['query' => $filters + $extra]);
    $counts = $data['counts'];

    $build = [
      '#attached' => ['library' => ['divengine_qa/qa']],
      '#cache' => [
        'tags' => ['qa_test_plan_list', 'qa_test_suite_list', 'qa_test_case_list', 'qa_test_step_list', 'qa_test_run_list', 'qa_test_execution_list', 'qa_issue_list', 'development_list'],
        'contexts' => ['user', 'url.query_args'],
      ],
    ];
    $build['toolbar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-qa-toolbar']],
      'filters' => $this->formBuilder()->getForm('Drupal\divengine_qa\Form\DashboardFilterForm'),
      'meta' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Last activity: @date', ['@date' => $data['latest_update'] ? \Drupal::service('date.formatter')->format($data['latest_update'], 'short') : '—']),
        '#attributes' => ['class' => ['dv-qa-toolbar__meta']],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['divengine-process-actions']],
        'plan' => $this->button($this->t('New Test Plan'), Url::fromRoute('entity.qa_test_plan.add_form', [], ['query' => $filters]), TRUE),
        'run' => $this->button($this->t('New Test Run'), Url::fromRoute('entity.qa_test_run.add_form')),
        'refresh' => $this->button($this->t('Refresh'), Url::fromRoute('divengine_qa.dashboard', [], ['query' => $filters])),
      ],
    ];

    $cards = [
      [$this->t('Test Plans'), $counts['test_plans'], $browse('plans'), 'info'],
      [$this->t('Test Suites'), $counts['test_suites'], $browse('suites'), 'info'],
      [$this->t('Test Cases'), $counts['test_cases'], $browse('cases'), 'info'],
      [$this->t('Test Steps'), $counts['test_steps'], $browse('steps'), 'neutral'],
      [$this->t('Test Runs'), $counts['test_runs'], $browse('runs'), 'warning'],
      [$this->t('Executions'), $counts['test_executions'], $browse('executions'), 'info'],
      [$this->t('Pass Rate'), number_format($counts['pass_rate'], 1) . '%', $browse('executions', ['state' => 'passed']), $this->rateTone($counts['pass_rate'])],
      [$this->t('QA Issues'), $counts['qa_issues'], Url::fromRoute('divengine_qa.browse', ['list' => 'issues'], ['query' => array_filter(['development' => $development])]), 'danger'],
    ];
    $build['cards'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards', 'dv-cards--wide']]];
    foreach ($cards as $i => [$label, $value, $url, $tone]) {
      $build['cards'][$i] = [
        '#type' => 'component',
        '#component' => 'divengine_core:stat-card',
        '#props' => ['label' => (string) $label, 'value' => (string) $value, 'tone' => $tone, 'url' => $url->toString()],
      ];
    }

    $results = array_map(fn ($item) => $item + ['url' => $browse('executions', ['state' => $item['key']])], $data['execution_states']);
    $stages = array_map(fn ($item) => $item + ['url' => $browse('plans', ['stage' => $item['key']])], $data['stages']);
    $environments = array_map(fn ($item) => $item + ['url' => Url::fromRoute('divengine_qa.browse', ['list' => 'plans'], ['query' => array_filter(['development' => $development, 'environment' => $item['key']])])], $data['environments']);
    $build['charts'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-qa-panels']],
      'results' => $this->doughnut($this->t('Execution Results'), $this->t('Distribution of test execution outcomes'), $results, $counts['test_executions'], $this->t('executions'), [
        '#type' => 'component',
        '#component' => 'divengine_core:badge',
        '#props' => ['label' => (string) $this->t('@rate% Pass Rate', ['@rate' => number_format($counts['pass_rate'], 1)]), 'tone' => $this->rateTone($counts['pass_rate'])],
      ]),
      'stages' => $this->doughnut($this->t('Test Stages'), $this->t('Test plans distribution across stages'), $stages, $counts['test_plans'], $this->t('plans')),
      'environments' => $this->doughnut($this->t('Test Environments'), $this->t('Test plans distribution across environments'), $environments, $counts['test_plans'], $this->t('plans')),
    ];

    $rows = [];
    foreach ($data['top_plans'] as $item) {
      $plan = $item['plan'];
      $rows[] = [
        ['data' => [
          'link' => $plan->toLink($plan->get('name')->value)->toRenderable(),
          'code' => ['#markup' => '<br><small>' . $plan->get('code')->value . '</small>'],
        ]],
        ['data' => $this->badge($plan->get('development')->entity?->label() ?? (string) $this->t('General'), 'neutral')],
        ['data' => $this->badge((string) $plan->get('environment')->entity?->label(), 'info')],
        ['data' => ['#plain_text' => (string) $item['suites']]],
        ['data' => ['#plain_text' => (string) $item['cases']]],
        ['data' => ['#plain_text' => (string) $item['runs']]],
        ['data' => $this->bar($item['pass_rate'], $item['pass_rate'] >= 80 ? '#22c55e' : ($item['pass_rate'] >= 50 ? '#f59e0b' : '#ef4444'), number_format($item['pass_rate'], 1) . '%')],
      ];
    }
    $build['performance'] = $this->section($this->t('Test Plans Performance'), $this->t('Test plan scope, execution volume and pass rates'), [
      '#type' => 'table',
      '#header' => [$this->t('Plan'), $this->t('Development'), $this->t('Env'), $this->t('Suites'), $this->t('Cases'), $this->t('Runs'), $this->t('Pass Rate')],
      '#rows' => $rows,
      '#empty' => $this->t('No test plans found.'),
    ]);

    $health = $data['health'];
    $gaps = [
      [$this->t('Active Test Plans'), $health['active_plans'], $browse('plans', ['state' => 'active'])],
      [$this->t('Runs In Progress'), $health['in_progress_runs'], $browse('runs', ['state' => 'in_progress'])],
      [$this->t('Test Cases without Steps'), $health['cases_without_steps'], $browse('cases', ['gap' => 'no_steps'])],
      [$this->t('Untested Cases'), $health['untested_cases'], $browse('cases', ['gap' => 'untested'])],
      [$this->t('Failed Executions'), $health['failed_executions'], $browse('executions', ['state' => 'failed'])],
      [$this->t('Blocked Executions'), $health['blocked_executions'], $browse('executions', ['state' => 'blocked'])],
      [$this->t('Open QA Issues'), $health['open_issues'], Url::fromRoute('divengine_qa.browse', ['list' => 'issues'], ['query' => array_filter(['development' => $development, 'state' => 'open'])])],
    ];
    $items = [];
    foreach ($gaps as [$label, $count, $url]) {
      $items[] = [
        '#type' => 'link',
        '#title' => ['label' => ['#markup' => '<span>' . $label . '</span>'], 'count' => ['#markup' => '<strong>' . $count . '</strong>']],
        '#url' => $url,
        '#attributes' => ['class' => ['dv-qa-gap']],
      ];
    }
    $priorities = [];
    foreach ($data['priorities'] as $item) {
      $priorities[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-qa-priority']],
        'label' => ['#markup' => '<div class="dv-qa-priority__head"><span>' . $item['label'] . '</span><span>' . $item['count'] . ' / ' . $counts['test_cases'] . '</span></div>'],
        'bar' => $this->bar($item['percentage'], $item['color'], (string) $this->t('@pct% of test cases', ['@pct' => number_format($item['percentage'], 1)])),
      ];
    }
    $build['health'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-qa-panels', 'dv-qa-panels--two']],
      'gaps' => $this->section($this->t('QA Quality & Gaps'), NULL, ['#type' => 'container', '#attributes' => ['class' => ['dv-qa-gaps']], 'items' => $items]),
      'priorities' => $this->section($this->t('Case Priorities'), $this->t('Distribution of test cases by severity level'), $priorities ?: ['#markup' => '<p>' . $this->t('No priority configured.') . '</p>']),
    ];

    $rows = [];
    foreach ($data['recent_executions'] as $execution) {
      $date = $execution->get('execution_date')->value ?: $execution->get('changed')->value;
      $rows[] = [
        ['data' => $execution->toLink($execution->get('snapshot_name')->value ?: $execution->label())->toRenderable()],
        ['data' => ['#plain_text' => (string) $execution->get('suite_name')->value]],
        ['data' => $this->badge((string) $execution->get('run')->entity?->label(), 'neutral')],
        ['data' => $this->badge((string) QaVocabulary::results()[$execution->getResult()], QaVocabulary::tone($execution->getResult()))],
        ['data' => ['#plain_text' => (string) $execution->get('run')->entity?->get('tester')->entity?->getDisplayName()]],
        ['data' => ['#plain_text' => (string) ($execution->get('actual_result')->value ?: $execution->get('comments')->value ?: '—')]],
        ['data' => ['#plain_text' => $date ? \Drupal::service('date.formatter')->format((int) $date, 'short') : '']],
      ];
    }
    $build['recent'] = $this->section($this->t('Recent Test Executions'), $this->t('Latest manual test execution results and tester notes'), [
      '#type' => 'table',
      '#header' => [$this->t('Test Case'), $this->t('Suite'), $this->t('Test Run'), $this->t('Status'), $this->t('Tester'), $this->t('Notes / Actual Result'), $this->t('Date')],
      '#rows' => $rows,
      '#empty' => $this->t('No executions recorded yet.'),
    ]);
    return $build;
  }

  /**
   * A filtered list of QA records ("QA Management").
   */
  public function browse(Request $request, string $list): array {
    if (!isset(static::BROWSE[$list])) {
      throw new NotFoundHttpException();
    }
    $type = static::BROWSE[$list];
    $storage = $this->entityTypeManager->getStorage($type);
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('id', 'DESC');
    $q = $request->query;
    if ($development = $q->get('development')) {
      $query->condition('development', $development);
    }
    if (($environment = $q->get('environment')) && $type !== 'qa_issue') {
      $query->condition($type === 'qa_test_plan' ? 'environment' : 'plan.entity:qa_test_plan.environment', $environment);
    }
    if ($stage = $q->get('stage')) {
      $query->condition('stage', $stage);
    }
    $state = $q->get('state');
    if ($type === 'qa_issue' && $state === 'open') {
      $query->condition('state', ['open', 'in_progress'], 'IN');
    }
    elseif ($state && in_array($type, ['qa_test_plan', 'qa_test_run', 'qa_test_execution', 'qa_issue', 'qa_test_case'], TRUE)) {
      $query->condition('state', $state);
    }
    if ($type === 'qa_test_execution') {
      $query->condition('run.entity:qa_test_run.state', 'cancelled', '<>');
    }
    if ($type === 'qa_test_case' && $q->get('gap') === 'untested') {
      $query->condition('last_result', 'no_exec');
    }
    $entities = $storage->loadMultiple($query->range(0, 500)->execute());
    if ($type === 'qa_test_case' && $q->get('gap') === 'no_steps') {
      $steps = $this->entityTypeManager->getStorage('qa_test_step');
      $entities = array_filter($entities, fn ($case) => !$steps->getQuery()->accessCheck(FALSE)->condition('case', $case->id())->count()->execute());
    }

    $rows = [];
    foreach ($entities as $entity) {
      $state_key = $entity->hasField('state') ? (string) $entity->get('state')->value : '';
      $allowed = $entity->hasField('state') ? options_allowed_values($entity->get('state')->getFieldDefinition()->getFieldStorageDefinition(), $entity) : [];
      $rows[] = [
        ['data' => ['#plain_text' => $entity->hasField('code') ? (string) $entity->get('code')->value : '']],
        ['data' => $entity->hasLinkTemplate('canonical') && $type !== 'qa_test_step' ? $entity->toLink()->toRenderable() : ['#plain_text' => $entity->label()]],
        ['data' => ['#plain_text' => (string) $entity->get('development')->entity?->label()]],
        ['data' => ['#plain_text' => $entity->hasField('plan') ? (string) $entity->get('plan')->entity?->label() : ($type === 'qa_test_plan' ? (string) $entity->get('environment')->entity?->label() : '')]],
        ['data' => $state_key !== '' ? $this->badge((string) ($allowed[$state_key] ?? $state_key), QaVocabulary::tone($state_key)) : []],
      ];
    }
    $label = $this->entityTypeManager->getDefinition($type)->getCollectionLabel();
    return [
      '#title' => $this->t('QA Management: @type', ['@type' => $label]),
      '#cache' => ['tags' => [$type . '_list'], 'contexts' => ['user', 'url.query_args']],
      'back' => ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'link' => $this->button($this->t('Back to dashboard'), Url::fromRoute('divengine_qa.dashboard', [], ['query' => array_filter(['development' => $development, 'environment' => $environment])]))],
      'list' => $this->section($label, NULL, [
        '#type' => 'table',
        '#header' => [$this->t('Reference'), $this->t('Name'), $this->t('Development'), $type === 'qa_test_plan' ? $this->t('Environment') : $this->t('Test Plan'), $this->t('Status')],
        '#rows' => $rows,
        '#empty' => $this->t('Nothing matches.'),
      ], count($rows)),
    ];
  }

  /**
   * Execution Reporting: executions of non-cancelled runs, suite × result.
   */
  public function executionReport(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('qa_test_execution');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('run.entity:qa_test_run.state', 'cancelled', '<>');
    if ($development = $request->query->get('development')) {
      $query->condition('development', $development);
    }
    $executions = $storage->loadMultiple($query->execute());
    $columns = array_map('strval', QaVocabulary::results());
    $pivot = [];
    foreach ($executions as $execution) {
      $pivot[(string) $execution->get('suite_name')->value][$execution->getResult()][] = $execution;
    }
    return $this->pivotPage($this->t('Execution Reporting'), $pivot, $columns, 'executions', $development);
  }

  /**
   * Test Case Reporting: cases, suite × state and state × priority.
   */
  public function caseReport(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('qa_test_case');
    $query = $storage->getQuery()->accessCheck(TRUE);
    if ($development = $request->query->get('development')) {
      $query->condition('development', $development);
    }
    $cases = $storage->loadMultiple($query->execute());
    $pivot = [];
    $by_priority = [];
    foreach ($cases as $case) {
      $pivot[(string) $case->get('suite')->entity?->label()][$case->getState()][] = $case;
      $by_priority[$case->getState()][(string) $case->get('priority')->target_id][] = $case;
    }
    $build = $this->pivotPage($this->t('Test Case Reporting'), $pivot, array_map('strval', QaVocabulary::caseStates()), 'cases', $development);
    $priorities = array_map(fn ($p) => $p->label(), $this->entityTypeManager->getStorage('qa_priority')->loadMultiple());
    $priorities[''] = (string) $this->t('No priority');
    $rows = [];
    foreach (QaVocabulary::caseStates() as $state => $label) {
      $row = [(string) $label];
      foreach (array_keys($priorities) as $priority) {
        $row[] = (string) count($by_priority[$state][$priority] ?? []);
      }
      $rows[] = $row;
    }
    $build['priorities'] = $this->section($this->t('Status by priority'), NULL, ['#type' => 'table', '#header' => array_merge([$this->t('Status')], array_values($priorities)), '#rows' => $rows]);
    return $build;
  }

  /**
   * Result Corrections: the audit trail of corrected results.
   */
  public function corrections(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('qa_result_correction');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('created', 'DESC')->sort('id', 'DESC');
    if ($run = $request->query->get('run')) {
      $query->condition('run', $run);
    }
    $rows = [];
    $results = QaVocabulary::results();
    foreach ($storage->loadMultiple($query->range(0, 500)->execute()) as $correction) {
      $execution = $correction->get('execution')->entity;
      $rows[] = [
        \Drupal::service('date.formatter')->format((int) $correction->get('created')->value, 'short'),
        (string) $correction->get('uid')->entity?->getDisplayName(),
        (string) $correction->get('run')->entity?->label(),
        ['data' => $execution ? $execution->toLink()->toRenderable() : []],
        (string) QaVocabulary::levels()[$correction->get('level')->value],
        (string) $correction->get('step_label')->value,
        (string) $results[$correction->get('old_state')->value],
        (string) $results[$correction->get('new_state')->value],
        (string) $correction->get('reason')->value,
      ];
    }
    return [
      '#cache' => ['tags' => ['qa_result_correction_list'], 'contexts' => ['user', 'url.query_args']],
      'list' => $this->section($this->t('Result Corrections'), NULL, [
        '#type' => 'table',
        '#header' => [$this->t('Corrected On'), $this->t('Corrected By'), $this->t('Test Run'), $this->t('Execution'), $this->t('Level'), $this->t('Step'), $this->t('Previous Result'), $this->t('New Result'), $this->t('Reason')],
        '#rows' => $rows,
        '#empty' => $this->t('No result was corrected.'),
      ], count($rows)),
    ];
  }

  /**
   * A pivot table with totals; each cell drills down to the list.
   */
  protected function pivotPage($title, array $pivot, array $columns, string $list, ?string $development): array {
    ksort($pivot);
    $rows = [];
    $totals = array_fill_keys(array_keys($columns), 0);
    foreach ($pivot as $group => $cells) {
      $row = [['data' => ['#plain_text' => $group !== '' ? $group : (string) $this->t('None')]]];
      $sum = 0;
      foreach (array_keys($columns) as $key) {
        $count = count($cells[$key] ?? []);
        $totals[$key] += $count;
        $sum += $count;
        $row[] = $count ? ['data' => Link::fromTextAndUrl((string) $count, Url::fromRoute('divengine_qa.browse', ['list' => $list], ['query' => array_filter(['development' => $development, 'state' => $key])]))->toRenderable()] : '0';
      }
      $row[] = ['data' => ['#markup' => '<strong>' . $sum . '</strong>']];
      $rows[] = $row;
    }
    $rows[] = array_merge([['data' => ['#markup' => '<strong>' . $this->t('Total') . '</strong>']]], array_map(fn ($n) => ['data' => ['#markup' => '<strong>' . $n . '</strong>']], array_values($totals)), [['data' => ['#markup' => '<strong>' . array_sum($totals) . '</strong>']]]);
    $chart = [];
    $sum = array_sum($totals);
    foreach ($columns as $key => $label) {
      $chart[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-qa-priority']],
        'head' => ['#type' => 'html_tag', '#tag' => 'div', '#value' => $label . ': ' . $totals[$key], '#attributes' => ['class' => ['dv-qa-priority__head']]],
        'bar' => $this->bar($sum ? $totals[$key] * 100 / $sum : 0, static::COLORS[$key] ?? '#3b82f6', ($sum ? number_format($totals[$key] * 100 / $sum, 1) : '0.0') . '%'),
      ];
    }
    return [
      '#title' => $title,
      '#attached' => ['library' => ['divengine_qa/qa']],
      '#cache' => ['tags' => ['qa_test_execution_list', 'qa_test_case_list', 'qa_test_run_list'], 'contexts' => ['user', 'url.query_args']],
      'graph' => $this->section($this->t('Graph'), NULL, $chart),
      'pivot' => $this->section($this->t('Pivot'), NULL, ['#type' => 'table', '#header' => array_merge([$this->t('Test Suite')], array_values($columns), [$this->t('Total')]), '#rows' => $rows, '#empty' => $this->t('No data.')]),
    ];
  }

  /**
   * A doughnut chart drawn with a CSS conic gradient, with its legend.
   */
  protected function doughnut($title, $subtitle, array $items, int $total, $unit, ?array $badge = NULL): array {
    $stops = [];
    $start = 0.0;
    $sum = array_sum(array_column($items, 'count'));
    foreach ($items as $item) {
      if (!$item['count']) {
        continue;
      }
      $end = $start + $item['count'] * 100 / $sum;
      $stops[] = sprintf('%s %.2f%% %.2f%%', $item['color'], $start, $end);
      $start = $end;
    }
    $gradient = $stops ? 'conic-gradient(' . implode(', ', $stops) . ')' : 'conic-gradient(#e2e8f0 0 100%)';
    $legend = [];
    foreach ($items as $item) {
      $legend[] = [
        '#type' => 'link',
        '#title' => [
          'dot' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['dv-qa-dot'], 'style' => 'background:' . $item['color']]],
          'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => (string) $item['label'], '#attributes' => ['class' => ['dv-qa-legend__label']]],
          'count' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $item['count'] . ' · ' . number_format((float) $item['percentage'], 1) . '%'],
        ],
        '#url' => $item['url'],
        '#attributes' => ['class' => ['dv-qa-legend__item'], 'title' => $item['label'] . ': ' . $item['count'] . ' (' . $item['percentage'] . '%)'],
      ];
    }
    return $this->section($title, $subtitle, [
      'chart' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-qa-doughnut'], 'style' => 'background:' . $gradient, 'role' => 'img', 'aria-label' => (string) $title],
        'center' => ['#markup' => '<span class="dv-qa-doughnut__center"><strong>' . $total . '</strong><small>' . $unit . '</small></span>'],
      ],
      'legend' => ['#type' => 'container', '#attributes' => ['class' => ['dv-qa-legend']], 'items' => $legend],
    ], NULL, $badge);
  }

  protected function bar(float $percentage, string $color, string $label): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-qa-bar']],
      'track' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['dv-qa-bar__track']],
        'fill' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['dv-qa-bar__fill'], 'style' => 'width:' . max(0, min(100, $percentage)) . '%;background:' . $color]],
      ],
      'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $label, '#attributes' => ['class' => ['dv-qa-bar__label']]],
    ];
  }

  protected function section($title, $subtitle, array $content, ?int $count = NULL, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''] + ($subtitle ? ['description' => (string) $subtitle] : []),
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function badge(string $label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => $label, 'tone' => $tone]];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

  protected function rateTone(float $rate): string {
    return $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
  }

}
