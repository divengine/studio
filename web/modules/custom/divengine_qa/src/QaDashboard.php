<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_qa\Entity\QaIssue;

/**
 * The figures of the QA dashboard, for a Development and/or Environment.
 *
 * Every query runs as the user, so the dashboard only counts what the user
 * may see. Metrics exclude cancelled runs; run counts include them.
 */
class QaDashboard {

  use StringTranslationTrait;

  const RESULT_COLORS = ['passed' => '#22c55e', 'failed' => '#ef4444', 'blocked' => '#f59e0b', 'skipped' => '#64748b', 'pending' => '#38bdf8'];

  const STAGE_COLORS = ['#3b82f6', '#8b5cf6', '#ec4899', '#10b981', '#f97316', '#64748b'];

  const ENVIRONMENT_COLORS = ['#f59e0b', '#38bdf8', '#22c55e', '#a855f7', '#64748b'];

  const PRIORITY_COLORS = ['critical' => '#ef4444', 'high' => '#f97316', 'medium' => '#3b82f6', 'normal' => '#3b82f6', 'low' => '#64748b'];

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  public function build(?string $development = NULL, ?string $environment = NULL): array {
    $load = fn (string $type, array $conditions = [], array $sort = []) => $this->load($type, $conditions, $sort);
    $plan_conditions = array_filter(['development' => $development, 'environment' => $environment]);
    $plans = $load('qa_test_plan', $plan_conditions, ['name' => 'ASC']);
    $filtered = (bool) $plan_conditions;
    $under = fn (string $type) => $filtered ? ($plans ? $load($type, ['plan' => array_keys($plans)]) : []) : $load($type);
    $suites = $under('qa_test_suite');
    $cases = $under('qa_test_case');
    $runs = $under('qa_test_run');
    $steps = $cases ? $load('qa_test_step', ['case' => array_keys($cases)]) : ($filtered ? [] : $load('qa_test_step'));
    $executions = $runs ? $load('qa_test_execution', ['run' => array_keys($runs)]) : [];
    $active_runs = array_filter($runs, fn ($run) => $run->getState() !== 'cancelled');
    $metric_executions = array_filter($executions, fn ($e) => isset($active_runs[$e->get('run')->target_id]));
    $metrics = QaMetrics::calculate(array_map(fn ($e) => $e->getResult(), $metric_executions));

    if ($runs) {
      $issues = $load('qa_issue', ['run' => array_keys($runs)]);
    }
    elseif ($development) {
      $issues = $load('qa_issue', ['development' => $development]);
    }
    else {
      $issues = $filtered ? [] : $load('qa_issue');
    }

    $total = count($metric_executions);
    $results = [];
    foreach (QaVocabulary::results() as $key => $label) {
      $count = $metrics[$key];
      $results[] = ['key' => $key, 'label' => (string) $label, 'count' => $count, 'percentage' => $total ? round($count * 100 / $total, 1) : 0.0, 'color' => static::RESULT_COLORS[$key]];
    }
    $distribution = function (string $config_type, string $field, array $colors) use ($plans) {
      $items = [];
      $i = 0;
      foreach ($this->entityTypeManager->getStorage($config_type)->loadMultiple() as $id => $entity) {
        $count = count(array_filter($plans, fn ($plan) => (string) $plan->get($field)->target_id === (string) $id));
        if ($plans || $count) {
          $items[] = ['key' => $id, 'label' => $entity->label(), 'count' => $count, 'percentage' => $plans ? round($count * 100 / count($plans), 1) : 0.0, 'color' => $colors[$i++ % count($colors)]];
        }
      }
      return $items;
    };
    $priorities = [];
    foreach ($this->entityTypeManager->getStorage('qa_priority')->loadMultiple() as $id => $priority) {
      $count = count(array_filter($cases, fn ($case) => (string) $case->get('priority')->target_id === (string) $id));
      $priorities[] = ['key' => $id, 'label' => $priority->label(), 'count' => $count, 'percentage' => $cases ? round($count * 100 / count($cases), 1) : 0.0, 'color' => static::PRIORITY_COLORS[mb_strtolower($priority->label())] ?? '#64748b'];
    }

    $cases_with_steps = array_flip(array_map(fn ($step) => (string) $step->get('case')->target_id, $steps));
    $tested = array_flip(array_map(fn ($e) => (string) $e->get('test_case')->target_id, $metric_executions));
    $latest = 0;
    foreach ([$plans, $runs, $executions, $cases] as $set) {
      foreach ($set as $entity) {
        $latest = max($latest, (int) ($entity->hasField('changed') ? $entity->get('changed')->value : 0));
      }
    }

    $metrics_service = \Drupal::service('divengine_qa.metrics');
    $top = [];
    foreach (array_slice($plans, 0, 8, TRUE) as $plan) {
      $figures = $metrics_service->plan($plan);
      $top[] = ['plan' => $plan, 'suites' => $figures['suites'], 'cases' => $figures['cases'], 'runs' => $figures['runs'], 'pass_rate' => round($figures['pass_rate'], 1)];
    }
    $recent = $executions;
    uasort($recent, fn ($a, $b) => ((int) ($b->get('execution_date')->value ?: $b->get('changed')->value)) <=> ((int) ($a->get('execution_date')->value ?: $a->get('changed')->value)));

    return [
      'latest_update' => $latest,
      'counts' => [
        'test_plans' => count($plans),
        'test_suites' => count($suites),
        'test_cases' => count($cases),
        'test_steps' => count($steps),
        'test_runs' => count($runs),
        'test_executions' => $total,
        'pass_rate' => round($metrics['pass_rate'], 1),
        'executed_count' => $metrics['executed'],
        'pending_count' => $metrics['pending'],
        'failure_count' => $metrics['failed'],
        'execution_progress' => round($metrics['execution_progress'], 1),
        'qa_issues' => count($issues),
      ],
      'execution_states' => $results,
      'stages' => $distribution('qa_stage', 'stage', static::STAGE_COLORS),
      'environments' => $distribution('qa_environment', 'environment', static::ENVIRONMENT_COLORS),
      'priorities' => $priorities,
      'health' => [
        'active_plans' => count(array_filter($plans, fn ($p) => $p->getState() === 'active')),
        'in_progress_runs' => count(array_filter($runs, fn ($r) => $r->getState() === 'in_progress')),
        'cases_without_steps' => count(array_filter($cases, fn ($c) => !isset($cases_with_steps[(string) $c->id()]))),
        'untested_cases' => count(array_filter($cases, fn ($c) => !isset($tested[(string) $c->id()]))),
        'failed_executions' => $metrics['failed'],
        'blocked_executions' => $metrics['blocked'],
        'open_issues' => count(array_filter($issues, fn (QaIssue $i) => $i->isOpen())),
      ],
      'top_plans' => $top,
      'recent_executions' => array_slice($recent, 0, 10, TRUE),
    ];
  }

  protected function load(string $type, array $conditions = [], array $sort = []): array {
    $storage = $this->entityTypeManager->getStorage($type);
    $query = $storage->getQuery()->accessCheck(TRUE);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value, is_array($value) ? 'IN' : '=');
    }
    foreach ($sort as $field => $direction) {
      $query->sort($field, $direction);
    }
    return $storage->loadMultiple($query->sort('id')->execute());
  }

}
