<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * The one formula of execution metrics, used by runs, plans and the
 * dashboard.
 *
 * Pass rate = Passed / (Passed + Failed); execution progress = executions
 * with a final result / total. Each is 0 when its denominator is 0.
 */
class QaMetrics {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Metrics over a list of execution results.
   *
   * @param string[] $states
   *
   * @return array{total: int, passed: int, failed: int, blocked: int, skipped: int, pending: int, executed: int, pass_rate: float, execution_progress: float}
   */
  public static function calculate(array $states): array {
    $counts = array_count_values($states) + ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0];
    $total = count($states);
    $executed = $counts['passed'] + $counts['failed'] + $counts['blocked'] + $counts['skipped'];
    $decided = $counts['passed'] + $counts['failed'];
    return [
      'total' => $total,
      'passed' => $counts['passed'],
      'failed' => $counts['failed'],
      'blocked' => $counts['blocked'],
      'skipped' => $counts['skipped'],
      'pending' => $total - $executed,
      'executed' => $executed,
      'pass_rate' => $decided ? $counts['passed'] / $decided * 100 : 0.0,
      'execution_progress' => $total ? $executed / $total * 100 : 0.0,
    ];
  }

  /**
   * The results of the executions of some runs.
   *
   * @param int[] $run_ids
   *
   * @return string[]
   */
  public function executionStates(array $run_ids): array {
    if (!$run_ids) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('qa_test_execution');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('run', $run_ids, 'IN')->execute();
    return array_map(fn ($execution) => (string) $execution->get('state')->value, $storage->loadMultiple($ids));
  }

  /**
   * Stores the counters of a run.
   */
  public function refreshRun(QaTestRun $run): void {
    $metrics = static::calculate($this->executionStates([(int) $run->id()]));
    $run->set('execution_count', $metrics['total'])
      ->set('pass_count', $metrics['passed'])
      ->set('fail_count', $metrics['failed'])
      ->set('blocked_count', $metrics['blocked'])
      ->set('skipped_count', $metrics['skipped'])
      ->set('pending_count', $metrics['pending'])
      ->set('pass_rate', round($metrics['pass_rate'], 2))
      ->set('execution_progress', round($metrics['execution_progress'], 2));
    $run->saveTrusted();
  }

  /**
   * Plan figures; the pass rate leaves out cancelled runs.
   *
   * @return array{suites: int, cases: int, ready_cases: int, runs: int, pass_rate: float}
   */
  public function plan(QaTestPlan $plan): array {
    $runs = $this->entityTypeManager->getStorage('qa_test_run');
    $run_ids = $runs->getQuery()->accessCheck(FALSE)->condition('plan', $plan->id())->execute();
    $active = $runs->getQuery()->accessCheck(FALSE)->condition('plan', $plan->id())->condition('state', 'cancelled', '<>')->execute();
    $count = fn (string $entity_type, array $conditions) => (int) $this->countWhere($entity_type, $conditions);
    return [
      'suites' => $count('qa_test_suite', ['plan' => $plan->id()]),
      'cases' => $count('qa_test_case', ['plan' => $plan->id()]),
      'ready_cases' => $count('qa_test_case', ['plan' => $plan->id(), 'state' => 'ready']),
      'runs' => count($run_ids),
      'pass_rate' => static::calculate($this->executionStates(array_values($active)))['pass_rate'],
    ];
  }

  /**
   * Recomputes the last result of a test case.
   *
   * The latest recorded execution, cancelled runs included; none, or a
   * pending one, is "Not Executed".
   */
  public function refreshCase(QaTestCase $case): void {
    $storage = $this->entityTypeManager->getStorage('qa_test_execution');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('test_case', $case->id())->execute();
    $latest = NULL;
    foreach ($storage->loadMultiple($ids) as $execution) {
      $date = (int) $execution->get('execution_date')->value;
      if ($latest === NULL || $date > (int) $latest->get('execution_date')->value) {
        $latest = $execution;
      }
    }
    $state = $latest && $latest->get('execution_date')->value && $latest->get('state')->value !== 'pending'
      ? (string) $latest->get('state')->value
      : 'no_exec';
    if ($case->get('last_result')->value !== $state) {
      $case->set('last_result', $state)->save();
    }
  }

  protected function countWhere(string $entity_type, array $conditions): int {
    $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()->accessCheck(FALSE);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return (int) $query->count()->execute();
  }

}
