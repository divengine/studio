<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\divengine_process\Lifecycle\LifecycleManager;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\Entity\QaTestSuite;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pages of the QA records: plan, suite, case, run and execution.
 *
 * Each opens with a status bar (reference, state, facts and the actions the
 * user may take now), then the sections that matter for that record. The
 * buttons follow the visibility rules of the workflow; the services still
 * check every rule when an action is taken.
 */
class QaViewBuilder extends EntityViewBuilder {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountInterface $currentUser;

  protected LifecycleManager $lifecycle;

  protected QaMetrics $metrics;

  protected QaRunWorkflow $runs;

  protected QaLogger $logger;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->lifecycle = $container->get('divengine_process.lifecycle');
    $instance->metrics = $container->get('divengine_qa.metrics');
    $instance->runs = $container->get('divengine_qa.runs');
    $instance->logger = $container->get('divengine_qa.logger');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $entity) {
      // The page shows the fields in its own sections.
      foreach (array_keys($entity->getFieldDefinitions()) as $field_name) {
        unset($build[$id][$field_name]);
      }
      $sections = match (TRUE) {
        $entity instanceof QaTestPlan => $this->plan($entity),
        $entity instanceof QaTestSuite => $this->suite($entity),
        $entity instanceof QaTestCase => $this->testCase($entity),
        $entity instanceof QaTestRun => $this->run($entity),
        $entity instanceof QaTestExecution => $this->execution($entity),
        default => [],
      };
      $sections['activity'] = $this->activity($entity);
      $build[$id] += $sections;
      $build[$id]['#cache']['tags'] = array_merge($build[$id]['#cache']['tags'] ?? [], [
        'qa_test_suite_list', 'qa_test_case_list', 'qa_test_step_list', 'qa_test_run_list',
        'qa_test_execution_list', 'qa_step_result_list', 'qa_result_correction_list', 'qa_log_list',
      ]);
      $build[$id]['#cache']['contexts'][] = 'user';
      $build[$id]['#attached']['library'][] = 'divengine_qa/qa';
    }
  }

  // ---------------------------------------------------------------- Plan.

  protected function plan(QaTestPlan $plan): array {
    $figures = $this->metrics->plan($plan);
    $lead = QaPermissions::isLead($this->currentUser);
    $actions = $this->transitions($plan);
    if ($lead) {
      $actions[] = $this->button($this->t('New Test Run'), Url::fromRoute('entity.qa_test_run.add_form', [], ['query' => ['plan' => $plan->id()]]));
      $actions[] = $this->button($this->t('Create Runs for Testers'), Url::fromRoute('divengine_qa.assign_runs', ['qa_test_plan' => $plan->id()]));
      $actions[] = $this->button($this->t('Clone'), Url::fromRoute('divengine_qa.clone_plan', ['qa_test_plan' => $plan->id()]));
    }
    elseif (QaPermissions::isTester($this->currentUser) && $plan->getState() === 'active') {
      $actions[] = $this->button($this->t('New Test Run'), Url::fromRoute('divengine_qa.my_run', [], ['query' => ['plan' => $plan->id()]]), TRUE);
    }
    $actions[] = $this->button($this->t('Print'), Url::fromRoute('divengine_qa.report_plan', ['qa_test_plan' => $plan->id()]));
    if ($plan->access('update')) {
      $actions[] = $this->button($this->t('Edit'), $plan->toUrl('edit-form'));
    }
    $build['status'] = $this->statusBar($plan, (string) $plan->get('code')->value, $plan->getStateLabel(), QaVocabulary::tone($plan->getState()), [
      (string) $this->t('Development') => $plan->get('development')->entity?->label(),
      (string) $this->t('Environment') => $plan->get('environment')->entity?->label(),
      (string) $this->t('Stage') => $plan->get('stage')->entity?->label(),
      (string) $this->t('Responsible') => $plan->get('responsible')->entity?->getDisplayName(),
    ], $actions) + ['#weight' => -100];
    $build['cards'] = $this->cards([
      [$this->t('Suites'), (string) $figures['suites'], '', 'neutral'],
      [$this->t('Test Cases'), (string) $figures['cases'], (string) $this->t('@ready Ready', ['@ready' => $figures['ready_cases']]), $figures['ready_cases'] ? 'info' : 'warning'],
      [$this->t('Runs'), (string) $figures['runs'], '', 'neutral'],
      [$this->t('Pass Rate'), $this->percent($figures['pass_rate']), (string) $this->t('Cancelled runs not counted'), $this->rateTone($figures['pass_rate'])],
    ]) + ['#weight' => -90];

    $suites = $this->children('qa_test_suite', 'plan', $plan->id());
    $design = [];
    foreach ($suites as $suite) {
      if ($suite->get('parent')->isEmpty()) {
        $design[] = $this->suiteTree($suite, $suites, 0);
      }
    }
    $add = $lead ? $this->button($this->t('Add suite'), Url::fromRoute('entity.qa_test_suite.add_form', [], ['query' => ['plan' => $plan->id()]]), TRUE) : NULL;
    $build['design'] = $this->section($this->t('Test design'), count($suites), $design ?: ['#markup' => '<p>' . $this->t('No suite yet: add the first one, then its test cases and their steps.') . '</p>'], $add) + ['#weight' => 50];

    $rows = [];
    foreach ($this->children('qa_test_run', 'plan', $plan->id(), 'created', 'DESC') as $run) {
      if (!$run->access('view')) {
        continue;
      }
      $rows[] = [
        ['data' => ['#plain_text' => (string) $run->get('code')->value]],
        ['data' => $run->toLink($run->get('name')->value)->toRenderable()],
        ['data' => ['#plain_text' => (string) $run->get('tester')->entity?->getDisplayName()]],
        ['data' => $this->badge($run->getStateLabel(), QaVocabulary::tone($run->getState()))],
        ['data' => ['#plain_text' => $this->percent((float) $run->get('execution_progress')->value)]],
        ['data' => ['#plain_text' => $this->percent((float) $run->get('pass_rate')->value)]],
      ];
    }
    $build['runs'] = $this->section($this->t('Runs'), count($rows), $this->table([$this->t('Reference'), $this->t('Run'), $this->t('Tester'), $this->t('Status'), $this->t('Progress'), $this->t('Pass Rate')], $rows, $this->t('No run yet.'))) + ['#weight' => 60];
    return $build;
  }

  /**
   * A suite with its cases and, indented, its child suites.
   */
  protected function suiteTree(QaTestSuite $suite, array $all, int $depth): array {
    $lead = QaPermissions::isLead($this->currentUser);
    $rows = [];
    foreach ($this->children('qa_test_case', 'suite', $suite->id()) as $case) {
      $rows[] = $this->caseRow($case);
    }
    $actions = [];
    if ($lead) {
      $actions[] = $this->button($this->t('Add case'), Url::fromRoute('entity.qa_test_case.add_form', [], ['query' => ['suite' => $suite->id()]]));
      $actions[] = $this->button($this->t('Add child suite'), Url::fromRoute('entity.qa_test_suite.add_form', [], ['query' => ['plan' => $suite->get('plan')->target_id, 'parent' => $suite->id()]]));
    }
    $actions[] = $this->button($this->t('Open'), $suite->toUrl());
    $tree = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-qa-suite'], 'style' => 'margin-left:' . ($depth * 1.5) . 'rem'],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-qa-suite__header']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $suite->label()],
        'actions' => $this->actionsContainer($actions),
      ],
      'cases' => $this->table([$this->t('Reference'), $this->t('Test Case'), $this->t('Priority'), $this->t('Steps'), $this->t('Status'), $this->t('Last Result')], $rows, $this->t('No test case in this suite.')),
    ];
    foreach ($all as $child) {
      if ((string) $child->get('parent')->target_id === (string) $suite->id()) {
        $tree['child_' . $child->id()] = $this->suiteTree($child, $all, $depth + 1);
      }
    }
    return $tree;
  }

  protected function caseRow(QaTestCase $case): array {
    $last = (string) $case->get('last_result')->value;
    return [
      ['data' => ['#plain_text' => (string) $case->get('code')->value]],
      ['data' => $case->toLink($case->get('name')->value)->toRenderable()],
      ['data' => ['#plain_text' => (string) $case->get('priority')->entity?->label()]],
      ['data' => ['#plain_text' => (string) count($this->children('qa_test_step', 'case', $case->id()))]],
      ['data' => $this->badge($case->getStateLabel(), QaVocabulary::tone($case->getState()))],
      ['data' => $last && $last !== 'no_exec' ? $this->badge((string) QaVocabulary::lastResults()[$last], QaVocabulary::tone($last)) : ['#plain_text' => (string) $this->t('Not Executed')]],
    ];
  }

  // ---------------------------------------------------------------- Suite.

  protected function suite(QaTestSuite $suite): array {
    $actions = [];
    if ($suite->access('update')) {
      $actions[] = $this->button($this->t('Add case'), Url::fromRoute('entity.qa_test_case.add_form', [], ['query' => ['suite' => $suite->id()]]), TRUE);
      $actions[] = $this->button($this->t('Edit'), $suite->toUrl('edit-form'));
    }
    $build['status'] = $this->statusBar($suite, (string) $suite->get('code')->value, (string) QaVocabulary::planStates()[$suite->get('state')->value], 'neutral', [
      (string) $this->t('Test Plan') => $suite->get('plan')->entity?->label(),
      (string) $this->t('Parent Suite') => $suite->get('parent')->entity?->label(),
    ], $actions) + ['#weight' => -100];
    $rows = array_map(fn ($case) => $this->caseRow($case), $this->children('qa_test_case', 'suite', $suite->id()));
    $build['cases'] = $this->section($this->t('Test Cases'), count($rows), $this->table([$this->t('Reference'), $this->t('Test Case'), $this->t('Priority'), $this->t('Steps'), $this->t('Status'), $this->t('Last Result')], $rows, $this->t('No test case in this suite.'))) + ['#weight' => 50];
    return $build;
  }

  // ----------------------------------------------------------------- Case.

  protected function testCase(QaTestCase $case): array {
    $actions = $this->transitions($case);
    if ($case->access('update')) {
      $actions[] = $this->button($this->t('Add step'), Url::fromRoute('entity.qa_test_step.add_form', [], ['query' => ['case' => $case->id()]]));
      $actions[] = $this->button($this->t('Edit'), $case->toUrl('edit-form'));
    }
    $last = (string) $case->get('last_result')->value;
    $build['status'] = $this->statusBar($case, (string) $case->get('code')->value, $case->getStateLabel(), QaVocabulary::tone($case->getState()), [
      (string) $this->t('Test Suite') => $case->get('suite')->entity?->label(),
      (string) $this->t('Priority') => $case->get('priority')->entity?->label(),
      (string) $this->t('Last Result') => (string) (QaVocabulary::lastResults()[$last] ?? ''),
    ], $actions) + ['#weight' => -100];

    $rows = [];
    $n = 1;
    foreach ($this->children('qa_test_step', 'case', $case->id()) as $step) {
      $rows[] = [
        ['data' => ['#plain_text' => (string) $n++]],
        ['data' => ['#plain_text' => (string) $step->get('name')->value]],
        ['data' => ['#plain_text' => (string) $step->get('expected_result')->value]],
        ['data' => $step->get('is_skippable')->value ? $this->badge($this->t('Optional'), 'info') : []],
        ['data' => $step->access('update') ? $this->actionsContainer([$this->button($this->t('Edit'), $step->toUrl('edit-form'))]) : []],
      ];
    }
    $build['steps'] = $this->section($this->t('Test Steps'), count($rows), $this->table(['#', $this->t('Action'), $this->t('Expected Result'), '', ''], $rows, $this->t('No step: a step-less case is recorded as a whole.'))) + ['#weight' => 40];

    $rows = [];
    foreach ($this->children('qa_test_execution', 'test_case', $case->id(), 'execution_date', 'DESC') as $execution) {
      if (!$execution->access('view')) {
        continue;
      }
      $rows[] = [
        ['data' => $execution->toLink()->toRenderable()],
        ['data' => ['#plain_text' => (string) $execution->get('run')->entity?->getStateLabel()]],
        ['data' => $this->resultBadge($execution->getResult())],
        ['data' => ['#plain_text' => $this->date($execution->get('execution_date')->value)]],
      ];
    }
    $build['executions'] = $this->section($this->t('Executions'), count($rows), $this->table([$this->t('Execution'), $this->t('Run Status'), $this->t('Result'), $this->t('Recorded On')], $rows, $this->t('Not executed yet.'))) + ['#weight' => 60];
    return $build;
  }

  // ------------------------------------------------------------------ Run.

  protected function run(QaTestRun $run): array {
    $lead = QaPermissions::isLead($this->currentUser);
    $assigned = (int) $run->get('tester')->target_id === (int) $this->currentUser->id() && QaPermissions::isTester($this->currentUser);
    $state = $run->getState();
    $executions = $this->runs->executions($run);
    $has_work = $this->runs->hasTesterWork($run);
    $action = fn (string $op) => Url::fromRoute('divengine_qa.run_action', ['qa_test_run' => $run->id(), 'action' => $op]);
    $actions = [];
    if ($state === 'draft' && $executions && ($lead || $assigned)) {
      $actions[] = $this->button($this->t('Start Run'), $action('start'), TRUE);
    }
    if ($state === 'draft' && !$executions && $lead) {
      $actions[] = $this->button($this->t('Generate Executions'), $action('generate'), TRUE);
    }
    if ($state === 'in_progress' && (int) $run->get('pending_count')->value === 0 && $lead) {
      $actions[] = $this->button($this->t('Complete'), $action('complete'), TRUE);
    }
    if (in_array($state, ['draft', 'in_progress'], TRUE) && !$run->get('tester')->isEmpty() && !$has_work && $lead) {
      $actions[] = $this->button($this->t('Reassign'), Url::fromRoute('divengine_qa.reassign_run', ['qa_test_run' => $run->id()]));
    }
    if ($state === 'in_progress' && $lead) {
      $actions[] = $this->button($this->t('Reset to Draft'), $action('reset'));
    }
    if (!$run->isHistorical() && $lead) {
      $actions[] = $this->button($this->t('Cancel'), $action('cancel'));
    }
    if ($state === 'completed' && QaPermissions::isManager($this->currentUser)) {
      $actions[] = $this->button($this->t('Reopen'), Url::fromRoute('divengine_qa.reopen_run', ['qa_test_run' => $run->id()]));
    }
    if ($url = $run->get('test_url')->value) {
      $actions[] = Link::fromTextAndUrl($this->t('Open Test Environment'), Url::fromUri($url, ['attributes' => ['target' => '_blank', 'rel' => 'noopener']]))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]];
    }
    $actions[] = $this->button($this->t('Print'), Url::fromRoute('divengine_qa.report_run', ['qa_test_run' => $run->id()]));
    if (!$run->isHistorical() && $lead) {
      $actions[] = $this->button($this->t('Edit'), $run->toUrl('edit-form'));
    }
    $plan = $run->get('plan')->entity;
    $build['status'] = $this->statusBar($run, (string) $run->get('code')->value, $run->getStateLabel(), QaVocabulary::tone($state), [
      (string) $this->t('Test Plan') => $plan?->label(),
      (string) $this->t('Tester') => $run->get('tester')->entity?->getDisplayName() . ($run->get('is_self_service')->value ? ' · ' . $this->t('self-created') : ''),
      (string) $this->t('Environment') => $plan?->get('environment')->entity?->label(),
      (string) $this->t('Started') => $this->date($run->get('start_date')->value),
      (string) $this->t('Ended') => $this->date($run->get('end_date')->value),
    ], $actions) + ['#weight' => -100];

    $banners = [];
    if (!$executions) {
      $banners[] = $this->t("This Run has no executions: none of the Plan's Test Cases were Ready when it was created. It cannot be started or completed until executions are generated.");
    }
    elseif ($state === 'in_progress') {
      $banners[] = (int) $run->get('pending_count')->value > 0
        ? $this->t('The Run completes automatically when the last result is recorded. Add any scenario you discovered before recording the last pending result.')
        : $this->t('Every execution has a result after the Run was reopened. Record a corrected result, or ask a QA Lead to complete the Run.');
    }
    if ($banners) {
      $build['banners'] = ['#theme' => 'status_messages', '#message_list' => ['warning' => $banners], '#status_headings' => ['warning' => $this->t('Warning message')], '#weight' => -95];
    }

    $count = fn (string $field) => (string) (int) $run->get($field)->value;
    $build['cards'] = $this->cards([
      [$this->t('Execution Progress'), $this->percent((float) $run->get('execution_progress')->value), (string) $this->t('@pending pending of @total', ['@pending' => $count('pending_count'), '@total' => $count('execution_count')]), 'info'],
      [$this->t('Pass Rate'), $this->percent((float) $run->get('pass_rate')->value), (string) $this->t('Passed / (Passed + Failed)'), $this->rateTone((float) $run->get('pass_rate')->value)],
      [$this->t('Passed'), $count('pass_count'), '', 'success'],
      [$this->t('Failed'), $count('fail_count'), '', $run->get('fail_count')->value ? 'danger' : 'neutral'],
      [$this->t('Blocked'), $count('blocked_count'), '', $run->get('blocked_count')->value ? 'warning' : 'neutral'],
      [$this->t('Skipped'), $count('skipped_count'), '', 'neutral'],
    ]) + ['#weight' => -90];

    $rows = [];
    foreach ($executions as $execution) {
      $steps = $execution->getStepResults();
      $recorded = count(array_filter($steps, fn (QaStepResult $s) => $s->getResult() !== 'pending'));
      $row_actions = [$this->button($this->t('Execute'), $execution->toUrl(), $state === 'in_progress' && $execution->getResult() === 'pending')];
      if ($steps && $execution->getResult() === 'pending' && !$recorded && $state === 'in_progress' && ($lead || $assigned)) {
        $row_actions[] = $this->button($this->t('Skip Test Case'), Url::fromRoute('divengine_qa.skip_case', ['qa_test_execution' => $execution->id()]));
      }
      $rows[] = [
        ['data' => ['#plain_text' => (string) $execution->get('suite_name')->value]],
        ['data' => ['#plain_text' => (string) $execution->get('snapshot_code')->value]],
        ['data' => [
          'name' => ['#plain_text' => (string) $execution->get('snapshot_name')->value],
          'origin' => $execution->isAdHoc() ? $this->badge($this->t('Ad hoc'), 'info') : [],
        ]],
        ['data' => $this->resultBadge($execution->getResult())],
        ['data' => ['#plain_text' => $steps ? $recorded . ' / ' . count($steps) : '—']],
        ['data' => ['#plain_text' => (string) ($execution->get('result_reason')->value ?? '')]],
        ['data' => $this->actionsContainer($row_actions)],
      ];
    }
    $add = $state === 'in_progress' && ($lead || $assigned)
      ? $this->button($this->t('Add Scenario'), Url::fromRoute('divengine_qa.add_scenario', ['qa_test_run' => $run->id()]))
      : NULL;
    $build['executions'] = $this->section($this->t('Executions'), count($rows), $this->table([$this->t('Test Suite'), $this->t('Case Code'), $this->t('Test Case / Scenario'), $this->t('Result'), $this->t('Steps Recorded'), $this->t('Reason'), ''], $rows, $this->t('No executions.')), $add) + ['#weight' => 40];

    if ((int) $run->get('reopen_count')->value > 0) {
      $build['reopen'] = $this->section($this->t('Reopen Audit'), (int) $run->get('reopen_count')->value, [
        '#type' => 'component',
        '#component' => 'divengine_core:fact-list',
        '#props' => ['rows' => [
          ['label' => (string) $this->t('Last Reopened By'), 'value' => (string) $run->get('last_reopened_by')->entity?->getDisplayName()],
          ['label' => (string) $this->t('Last Reopened At'), 'value' => $this->date($run->get('last_reopened_at')->value)],
          ['label' => (string) $this->t('Last Reopen Reason'), 'value' => (string) $run->get('last_reopen_reason')->value],
        ]],
      ]) + ['#weight' => 70];
    }
    return $build;
  }

  // ------------------------------------------------------------ Execution.

  protected function execution(QaTestExecution $execution): array {
    $run = $execution->get('run')->entity;
    $lead = QaPermissions::isLead($this->currentUser);
    $assigned = (int) $run->get('tester')->target_id === (int) $this->currentUser->id() && QaPermissions::isTester($this->currentUser);
    $can_record = $run->getState() === 'in_progress' && ($lead || $assigned);
    $steps = $execution->getStepResults();
    $result = $execution->getResult();
    $record = fn (string $state, string $type = 'execution', $id = NULL, array $extra = []) => Url::fromRoute('divengine_qa.record_result', ['type' => $type, 'id' => $id ?? $execution->id()], ['query' => array_filter(['state' => $state] + $extra)]);

    $actions = [];
    if ($can_record) {
      if (!$steps && $result === 'pending') {
        $actions[] = $this->button($this->t('Pass'), Url::fromRoute('divengine_qa.pass', ['type' => 'execution', 'id' => $execution->id()]), TRUE);
        $actions[] = $this->button($this->t('Fail'), $record('failed'));
        $actions[] = $this->button($this->t('Block'), $record('blocked'));
        $actions[] = $this->button($this->t('Skip'), $record('skipped'));
      }
      if ((!$steps || $execution->isCaseSkipped()) && $result !== 'pending') {
        $actions[] = $this->button($this->t('Correct Result'), Url::fromRoute('divengine_qa.record_result', ['type' => 'execution', 'id' => $execution->id()]));
      }
      if ($steps && !$execution->isCaseSkipped()) {
        $pending = array_filter($steps, fn (QaStepResult $s) => $s->getResult() === 'pending');
        if ($result === 'pending' && count($pending) === count($steps)) {
          $actions[] = $this->button($this->t('Skip Test Case'), Url::fromRoute('divengine_qa.skip_case', ['qa_test_execution' => $execution->id()]));
        }
        if ($pending) {
          $actions[] = $this->button($this->t('Block Remaining Steps'), $record('blocked', 'execution', NULL, ['bulk' => 1]));
          if (!array_filter($pending, fn (QaStepResult $s) => !$s->isOptional())) {
            $actions[] = $this->button($this->t('Skip Remaining Steps'), $record('skipped', 'execution', NULL, ['bulk' => 1]));
          }
        }
      }
      $actions[] = $this->button($this->t('Add Scenario'), Url::fromRoute('divengine_qa.add_scenario', ['qa_test_run' => $run->id()]));
    }
    if ($execution->isAdHoc() && $lead) {
      $actions[] = $this->button($this->t('Promote to Test Case'), Url::fromRoute('divengine_qa.promote_scenario', ['qa_test_execution' => $execution->id()]));
    }
    if (in_array($run->getState(), ['in_progress', 'completed'], TRUE) && ($lead || $assigned)) {
      $actions[] = $this->button($this->t('Add evidence'), Url::fromRoute('divengine_qa.add_evidence', ['type' => 'execution', 'id' => $execution->id()]));
    }
    foreach (['previous' => $this->t('Previous'), 'next' => $this->t('Next'), 'pending' => $this->t('Next Pending')] as $direction => $label) {
      $actions[] = $this->button($label, Url::fromRoute('divengine_qa.navigate', ['qa_test_execution' => $execution->id(), 'direction' => $direction]));
    }
    $facts = [
      (string) $this->t('Test Run') => $run->label(),
      (string) $this->t('Test Suite') => $execution->get('suite_name')->value,
      (string) $this->t('Tester') => $run->get('tester')->entity?->getDisplayName(),
    ];
    if ($execution->isAdHoc()) {
      $facts[(string) $this->t('Discovered')] = (string) $this->t('by @user on @date', ['@user' => $execution->get('discovered_by')->entity?->getDisplayName(), '@date' => $this->date($execution->get('discovered_on')->value)]);
    }
    $build['status'] = $this->statusBar($execution, trim((string) $execution->get('snapshot_code')->value . ' ' . $execution->get('snapshot_name')->value), (string) QaVocabulary::results()[$result], QaVocabulary::tone($result), $facts, $actions) + ['#weight' => -100];

    $banners = [];
    if ($run->getState() === 'draft') {
      $banners[] = $this->t('This Test Run has not been started yet. Results and evidence can be recorded once the Run is In Progress.');
    }
    if ($run->isHistorical()) {
      $banners[] = $this->t('This execution belongs to a Completed or Cancelled Run and is read-only historical evidence.');
    }
    if ($execution->isCaseSkipped()) {
      $banners[] = $this->t('<strong>Test Case skipped as a whole:</strong> @reason — its steps were not executed and are not marked Skipped. Use Correct Result → Pending to undo it while the Run is In Progress.', ['@reason' => $execution->get('result_reason')->value]);
    }
    if ($banners) {
      $build['banners'] = ['#theme' => 'status_messages', '#message_list' => ['warning' => $banners], '#status_headings' => ['warning' => $this->t('Warning message')], '#weight' => -95];
    }

    $build['instructions'] = $this->section($this->t('Instructions (frozen when the Run was created)'), 0, $this->facts([
      (string) $this->t('Objective') => $execution->get('snapshot_description')->value,
      (string) $this->t('Preconditions') => $execution->get('snapshot_preconditions')->value,
      (string) $this->t('Test Data') => $execution->get('snapshot_test_data')->value,
    ])) + ['#weight' => -80];

    $rows = [];
    $n = 1;
    foreach ($steps as $step) {
      $step_actions = [];
      $step_state = $step->getResult();
      if ($can_record && !$execution->isCaseSkipped()) {
        if ($step_state === 'pending') {
          $step_actions[] = $this->button('✓', Url::fromRoute('divengine_qa.pass', ['type' => 'step', 'id' => $step->id()]), FALSE, $this->t('Pass'));
          $step_actions[] = $this->button('✗', $record('failed', 'step', $step->id()), FALSE, $this->t('Fail'));
          $step_actions[] = $this->button('⊘', $record('blocked', 'step', $step->id()), FALSE, $this->t('Block'));
          if ($step->isOptional()) {
            $step_actions[] = $this->button('»', $record('skipped', 'step', $step->id()), FALSE, $this->t('Skip'));
          }
        }
        else {
          $step_actions[] = $this->button('✎', Url::fromRoute('divengine_qa.record_result', ['type' => 'step', 'id' => $step->id()]), FALSE, $this->t('Correct Result'));
        }
      }
      if (in_array($run->getState(), ['in_progress', 'completed'], TRUE) && ($lead || $assigned)) {
        $step_actions[] = $this->button('📎', Url::fromRoute('divengine_qa.add_evidence', ['type' => 'step', 'id' => $step->id()]), FALSE, $this->t('Add evidence'));
      }
      $rows[] = [
        ['data' => ['#plain_text' => (string) $n++]],
        ['data' => ['#plain_text' => (string) $step->get('action')->value]],
        ['data' => ['#plain_text' => (string) $step->get('expected_result')->value]],
        ['data' => ['#plain_text' => (string) ($step->get('actual_result')->value ?? '')]],
        ['data' => $this->resultBadge($step_state)],
        ['data' => ['#plain_text' => (string) ($step->get('result_reason')->value ?? '')]],
        ['data' => $step->isOptional() ? $this->badge($this->t('Optional'), 'info') : []],
        ['data' => ['#plain_text' => (string) count($step->get('evidence'))]],
        ['data' => $this->actionsContainer($step_actions)],
      ];
    }
    $build['steps'] = $this->section($this->t('Steps'), count($rows), $steps
      ? $this->table(['#', $this->t('Action'), $this->t('Expected'), $this->t('Actual Result'), $this->t('Result'), $this->t('Reason'), '', $this->t('Evidence'), ''], $rows, '')
      : ['#markup' => '<p>' . $this->t('This test case has no individual steps. Record the outcome with the result buttons and describe it under Execution Notes.') . '</p>']) + ['#weight' => -70];

    $build['outcome'] = $this->section($this->t('Expected Outcome'), 0, $this->facts([
      (string) ($execution->isAdHoc() ? $this->t('Expected Result') : $this->t('Acceptance Criteria')) => $execution->get('snapshot_acceptance_criteria')->value,
      (string) $this->t('Postconditions') => $execution->get('snapshot_postconditions')->value,
    ])) + ['#weight' => -60];
    $build['notes'] = $this->section($this->t('Execution Notes'), 0, $this->facts([
      (string) $this->t('Actual Result') => $execution->get('actual_result')->value,
      (string) $this->t('Reason') => $execution->get('result_reason')->value,
      (string) $this->t('Comments') => $execution->get('comments')->value,
      (string) $this->t('Last Correction Reason') => $execution->get('correction_reason')->value,
      (string) $this->t('Recorded By') => $execution->get('executed_by')->entity?->getDisplayName(),
      (string) $this->t('Recorded On') => $this->date($execution->get('execution_date')->value),
    ])) + ['#weight' => -50];

    $files = [];
    foreach (array_merge([$execution], $steps) as $holder) {
      foreach ($holder->get('evidence') as $item) {
        if ($file = $item->entity) {
          $files[] = ['#type' => 'link', '#title' => $file->getFilename() . ($holder instanceof QaStepResult ? ' (' . $holder->label() . ')' : ''), '#url' => Url::fromUri($file->createFileUrl(FALSE))];
        }
      }
    }
    $build['evidence'] = $this->section($this->t('Evidence'), count($files), $files ? ['#theme' => 'item_list', '#items' => $files] : ['#markup' => '<p>' . $this->t('No evidence attached.') . '</p>']) + ['#weight' => -40];

    $corrections = $this->children('qa_result_correction', 'execution', $execution->id(), 'created', 'DESC');
    if ($corrections) {
      $rows = [];
      foreach ($corrections as $correction) {
        $rows[] = [
          ['data' => ['#plain_text' => $this->date($correction->get('created')->value)]],
          ['data' => ['#plain_text' => (string) $correction->get('uid')->entity?->getDisplayName()]],
          ['data' => ['#plain_text' => (string) QaVocabulary::levels()[$correction->get('level')->value]]],
          ['data' => ['#plain_text' => (string) $correction->get('step_label')->value]],
          ['data' => $this->resultBadge($correction->get('old_state')->value)],
          ['data' => $this->resultBadge($correction->get('new_state')->value)],
          ['data' => ['#plain_text' => (string) $correction->get('reason')->value]],
        ];
      }
      $build['corrections'] = $this->section($this->t('Correction History'), count($rows), $this->table([$this->t('Corrected On'), $this->t('Corrected By'), $this->t('Level'), $this->t('Step'), $this->t('Previous Result'), $this->t('New Result'), $this->t('Reason')], $rows, '')) + ['#weight' => -30];
    }
    return $build;
  }

  // --------------------------------------------------------------- Shared.

  /**
   * The activity log of a record.
   */
  protected function activity(EntityInterface $entity): array {
    $rows = [];
    foreach ($this->logger->entries($entity) as $entry) {
      $rows[] = [
        ['data' => ['#plain_text' => $this->date($entry->get('created')->value)]],
        ['data' => ['#plain_text' => (string) $entry->get('uid')->entity?->getDisplayName()]],
        // Messages are built with t() placeholders: values are escaped.
        ['data' => ['#markup' => $entry->get('message')->value]],
      ];
    }
    if (!$rows) {
      return [];
    }
    return $this->section($this->t('Activity'), count($rows), $this->table([$this->t('On'), $this->t('By'), $this->t('What happened')], $rows, '')) + ['#weight' => 200];
  }

  /**
   * Plan and case transitions the user may apply.
   */
  protected function transitions(EntityInterface $entity): array {
    if (!$entity->access('update')) {
      return [];
    }
    $links = [];
    foreach ($this->lifecycle->available($entity) as $id => $transition) {
      $links[] = $this->button($transition->label, $this->lifecycle->url($entity, $id, $entity->toUrl()), !$links);
    }
    return $links;
  }

  /**
   * Records of a type pointing at a parent, ordered.
   */
  protected function children(string $entity_type, string $field, $id, string $sort = 'weight', string $direction = 'ASC'): array {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(FALSE)->condition($field, $id);
    $definitions = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions($entity_type);
    if (isset($definitions[$sort])) {
      $query->sort($sort, $direction);
    }
    return $storage->loadMultiple($query->sort('id')->execute());
  }

  protected function statusBar(EntityInterface $entity, string $code, string|\Stringable $state, string $tone, array $facts, array $actions): array {
    $rows = [];
    foreach ($facts as $label => $value) {
      if ($value !== NULL && $value !== '') {
        $rows[] = ['label' => (string) $label, 'value' => (string) $value];
      }
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => $code, 'facts' => $rows],
      '#slots' => array_filter([
        'badge' => $this->badge($state, $tone),
        'actions' => $actions ? $this->actionsContainer($actions) : NULL,
      ]),
    ];
  }

  protected function cards(array $cards): array {
    $build = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach ($cards as $i => [$label, $value, $hint, $tone]) {
      $build[$i] = [
        '#type' => 'component',
        '#component' => 'divengine_core:stat-card',
        '#props' => array_filter(['label' => (string) $label, 'value' => $value, 'hint' => $hint, 'tone' => $tone], fn ($v) => $v !== NULL && $v !== ''),
      ];
    }
    return $build;
  }

  protected function facts(array $facts): array {
    $rows = [];
    foreach ($facts as $label => $value) {
      if ($value !== NULL && trim((string) $value) !== '') {
        $rows[] = ['label' => (string) $label, 'value' => (string) $value];
      }
    }
    return ['#type' => 'component', '#component' => 'divengine_core:fact-list', '#props' => ['rows' => $rows, 'empty' => (string) $this->t('Nothing specified.')]];
  }

  protected function section(string|\Stringable $title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function table(array $header, array $rows, string|\Stringable $empty): array {
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $empty];
  }

  protected function badge(string|\Stringable $label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => (string) $label, 'tone' => $tone]];
  }

  protected function resultBadge(?string $state): array {
    $state = $state ?: 'pending';
    return $this->badge((string) (QaVocabulary::results()[$state] ?? $state), QaVocabulary::tone($state));
  }

  protected function button(string|\Stringable $label, Url $url, bool $primary = FALSE, string|\Stringable|null $title = NULL): array {
    $attributes = ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])];
    if ($title) {
      $attributes['title'] = (string) $title;
      $attributes['aria-label'] = (string) $title;
    }
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => $attributes];
  }

  protected function actionsContainer(array $actions): array {
    return ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'actions' => $actions];
  }

  protected function percent(float $value): string {
    return number_format($value, 1) . '%';
  }

  protected function rateTone(float $rate): string {
    return $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
  }

  protected function date($timestamp): string {
    return $timestamp ? \Drupal::service('date.formatter')->format((int) $timestamp, 'short') : '';
  }

}
