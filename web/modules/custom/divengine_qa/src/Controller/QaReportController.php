<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\QaVocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Printable documents: the Test Plan Specification and the Test Run
 * Execution Report. The browser prints them or saves them as PDF.
 */
class QaReportController extends ControllerBase {

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Test Plan Specification.
   */
  public function plan(QaTestPlan $qa_test_plan): array {
    $plan = $qa_test_plan;
    $build = $this->document((string) $this->t('Test Plan Specification'), $plan->label());
    $build['header'] = $this->header([
      (string) $this->t('Reference') => $plan->get('code')->value,
      (string) $this->t('Development') => $plan->get('development')->entity?->label(),
      (string) $this->t('Status') => $plan->getStateLabel(),
      (string) $this->t('Responsible') => $plan->get('responsible')->entity?->getDisplayName(),
      (string) $this->t('Environment') => $plan->get('environment')->entity?->label(),
      (string) $this->t('Stage') => $plan->get('stage')->entity?->label(),
      (string) $this->t('Start Date') => $plan->get('start_date')->value,
      (string) $this->t('End Date') => $plan->get('end_date')->value,
    ]);
    if ($text = $plan->get('description')->value) {
      $build['objective'] = $this->block($this->t('Objective / Scope'), $text);
    }
    $suites = $this->load('qa_test_suite', 'plan', $plan->id());
    $build['suites_title'] = ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Test Suites (@n)', ['@n' => count($suites)])];
    $all_cases = [];
    foreach ($suites as $suite) {
      $cases = $this->load('qa_test_case', 'suite', $suite->id());
      $all_cases = array_merge($all_cases, $cases);
      $title = $suite->get('code')->value . ' - ' . $suite->get('name')->value;
      if ($parent = $suite->get('parent')->entity) {
        $title .= ' (' . $this->t('parent: @parent', ['@parent' => $parent->get('name')->value]) . ')';
      }
      $rows = [];
      foreach ($cases as $case) {
        $rows[] = [
          $case->get('code')->value . ' - ' . $case->get('name')->value,
          (string) $case->get('priority')->entity?->label(),
          $case->getStateLabel(),
          (string) count($this->load('qa_test_step', 'case', $case->id())),
        ];
      }
      $build['suite_' . $suite->id()] = [
        'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $title],
        'description' => $suite->get('description')->value ? ['#type' => 'html_tag', '#tag' => 'p', '#value' => $suite->get('description')->value, '#attributes' => ['class' => ['dv-report__note']]] : [],
        'cases' => ['#type' => 'table', '#header' => [$this->t('Case'), $this->t('Priority'), $this->t('Status'), $this->t('Steps')], '#rows' => $rows, '#empty' => $this->t('No test case.')],
      ];
    }
    foreach ($all_cases as $case) {
      $rows = [];
      $n = 1;
      foreach ($this->load('qa_test_step', 'case', $case->id()) as $step) {
        $rows[] = [(string) $n++, (string) $step->get('name')->value, (string) $step->get('expected_result')->value];
      }
      $build['case_' . $case->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-report__block']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h5', '#value' => $case->get('code')->value . ' - ' . $case->get('name')->value],
        'facts' => $this->facts([
          (string) $this->t('Objective') => $case->get('description')->value,
          (string) $this->t('Preconditions') => $case->get('preconditions')->value,
          (string) $this->t('Test Data / Setup Notes') => $case->get('test_data')->value,
          (string) $this->t('Acceptance Criteria') => $case->get('acceptance_criteria')->value,
          (string) $this->t('Postconditions') => $case->get('postconditions')->value,
        ]),
        'steps' => $rows ? ['#type' => 'table', '#header' => ['#', $this->t('Step / Action'), $this->t('Expected Result')], '#rows' => $rows] : [],
      ];
    }
    return $build;
  }

  /**
   * Test Run Execution Report.
   */
  public function run(QaTestRun $qa_test_run): array {
    $run = $qa_test_run;
    $plan = $run->get('plan')->entity;
    $build = $this->document((string) $this->t('Test Run Execution Report'), $run->label());
    $build['header'] = $this->header([
      (string) $this->t('Reference') => $run->get('code')->value,
      (string) $this->t('Test Plan') => $plan?->label(),
      (string) $this->t('Development') => $run->get('development')->entity?->label(),
      (string) $this->t('Status') => $run->getStateLabel(),
      (string) $this->t('Tester') => $run->get('tester')->entity?->getDisplayName(),
      (string) $this->t('Environment') => $plan?->get('environment')->entity?->label(),
      (string) $this->t('Start Date') => $this->date($run->get('start_date')->value),
      (string) $this->t('End Date') => $this->date($run->get('end_date')->value),
    ] + ($run->get('test_url')->value ? [(string) $this->t('Test URL') => $run->get('test_url')->value] : []));
    if ($text = $run->get('notes')->value ?? NULL) {
      $build['notes'] = $this->block($this->t('Run Notes'), $text);
    }
    $count = fn (string $field) => (string) (int) $run->get($field)->value;
    $build['summary_title'] = ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Summary')];
    $build['summary'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Passed: @n', ['@n' => $count('pass_count')]), $this->t('Failed: @n', ['@n' => $count('fail_count')]), $this->t('Blocked: @n', ['@n' => $count('blocked_count')]), $this->t('Skipped: @n', ['@n' => $count('skipped_count')])],
        [$this->t('Pending: @n', ['@n' => $count('pending_count')]), $this->t('Total: @n', ['@n' => $count('execution_count')]), $this->t('Pass Rate: @r%', ['@r' => number_format((float) $run->get('pass_rate')->value, 1)]), $this->t('Progress: @r%', ['@r' => number_format((float) $run->get('execution_progress')->value, 1)])],
      ],
    ];
    $executions = \Drupal::service('divengine_qa.runs')->executions($run);
    $results = QaVocabulary::results();
    $rows = [];
    foreach ($executions as $execution) {
      $evidence = count($execution->get('evidence'));
      foreach ($execution->getStepResults() as $step) {
        $evidence += count($step->get('evidence'));
      }
      $rows[] = [
        (string) $execution->get('snapshot_code')->value,
        (string) $execution->get('snapshot_name')->value,
        (string) $execution->get('suite_name')->value,
        (string) $results[$execution->getResult()],
        (string) $evidence,
      ];
    }
    $build['executions_title'] = ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Executions (@n)', ['@n' => count($executions)])];
    $build['executions'] = ['#type' => 'table', '#header' => [$this->t('Case Code'), $this->t('Test Case'), $this->t('Suite'), $this->t('Result'), $this->t('Evidence')], '#rows' => $rows, '#empty' => $this->t('No executions.')];

    foreach ($executions as $execution) {
      $title = trim($execution->get('snapshot_code')->value . ' - ' . $execution->get('snapshot_name')->value, ' -');
      if ($execution->isAdHoc()) {
        $title .= ' ' . $this->t('(ad hoc, discovered during this Run)');
      }
      $result = (string) $this->t('Result: @state', ['@state' => $results[$execution->getResult()]]);
      if ($execution->isCaseSkipped()) {
        $result .= ' ' . $this->t('(whole Test Case skipped: @reason)', ['@reason' => $execution->get('result_reason')->value]);
      }
      $steps = [];
      $n = 1;
      foreach ($execution->getStepResults() as $step) {
        $steps[] = [
          'data' => [(string) $n++, (string) $step->get('action')->value, (string) $step->get('expected_result')->value, (string) $step->get('actual_result')->value, (string) $results[$step->getResult()], (string) count($step->get('evidence'))],
          'class' => $step->getResult() === 'failed' ? ['dv-report__failed'] : [],
        ];
      }
      $build['execution_' . $execution->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-report__block', $execution->getResult() === 'failed' ? 'dv-report__block--failed' : '']],
        'heading' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dv-report__heading']],
          'title' => ['#type' => 'html_tag', '#tag' => 'h5', '#value' => $title],
          'result' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $result],
        ],
        'facts' => $this->facts([
          (string) $this->t('Preconditions') => $execution->get('snapshot_preconditions')->value,
          (string) $this->t('Description') => $execution->get('snapshot_description')->value,
          (string) $this->t('Test Data') => $execution->get('snapshot_test_data')->value,
          (string) $this->t('Acceptance Criteria') => $execution->get('snapshot_acceptance_criteria')->value,
          (string) $this->t('Postconditions') => $execution->get('snapshot_postconditions')->value,
          (string) $this->t('Actual Result') => $execution->get('actual_result')->value,
          (string) $this->t('Comments') => $execution->get('comments')->value,
          (string) $this->t('Execution Date') => $this->date($execution->get('execution_date')->value) ?: '-',
        ]),
        'steps' => $steps ? ['#type' => 'table', '#header' => ['#', $this->t('Step / Action'), $this->t('Expected'), $this->t('Actual'), $this->t('Result'), $this->t('Evidence')], '#rows' => $steps] : [],
      ];
    }
    return $build;
  }

  protected function document(string $kind, string $title): array {
    return [
      '#attached' => ['library' => ['divengine_qa/report']],
      '#cache' => ['tags' => ['qa_test_suite_list', 'qa_test_case_list', 'qa_test_step_list', 'qa_test_execution_list', 'qa_step_result_list']],
      'print' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Print or save as PDF'),
        '#attributes' => ['type' => 'button', 'class' => ['button', 'button--primary', 'dv-report__print'], 'data-dv-print' => ''],
      ],
      'kind' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $kind, '#attributes' => ['class' => ['dv-report__kind']]],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $title],
    ];
  }

  protected function header(array $facts): array {
    $rows = [];
    $pairs = [];
    foreach ($facts as $label => $value) {
      $pairs[] = [['data' => (string) $label, 'header' => TRUE], (string) ($value ?: '-')];
    }
    foreach (array_chunk($pairs, 2) as $chunk) {
      $rows[] = array_merge(...$chunk);
    }
    return ['#type' => 'table', '#rows' => $rows, '#attributes' => ['class' => ['dv-report__header']]];
  }

  protected function block($title, string $text): array {
    return [
      'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $title],
      'text' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text],
    ];
  }

  protected function facts(array $facts): array {
    $items = [];
    foreach ($facts as $label => $value) {
      if ($value !== NULL && trim((string) $value) !== '') {
        $items[] = ['#markup' => '<strong>' . $label . ':</strong> ' . nl2br((string) \Drupal\Component\Utility\Html::escape((string) $value))];
      }
    }
    return $items ? ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['dv-report__facts']]] : [];
  }

  protected function load(string $type, string $field, $id): array {
    $storage = $this->entityTypeManager->getStorage($type);
    return $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition($field, $id)->sort('weight')->sort('id')->execute());
  }

  protected function date($timestamp): string {
    return $timestamp ? \Drupal::service('date.formatter')->format((int) $timestamp, 'short') : '';
  }

}
