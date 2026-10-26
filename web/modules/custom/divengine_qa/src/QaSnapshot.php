<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * Freezes the plan into a run: one execution per Ready case, one step result
 * per step.
 *
 * The copy is taken when the executions are generated: when the run is
 * created, or later by a Lead for a Draft run whose plan had no Ready cases
 * then. The run records that moment as its snapshot date. The copy never
 * changes afterwards: editing the plan does not touch existing runs, and new
 * Ready cases are never injected into a run that already has executions.
 */
class QaSnapshot {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QaMetrics $metrics,
    protected TimeInterface $time,
  ) {}

  /**
   * Builds the snapshot of a run that has no executions yet.
   *
   * @return int
   *   The number of executions created.
   */
  public function build(QaTestRun $run): int {
    $executions = $this->entityTypeManager->getStorage('qa_test_execution');
    if ($executions->getQuery()->accessCheck(FALSE)->condition('run', $run->id())->count()->execute()) {
      return 0;
    }
    $suites = $this->entityTypeManager->getStorage('qa_test_suite');
    $suite_ids = $suites->getQuery()->accessCheck(FALSE)->condition('plan', $run->get('plan')->target_id)->execute();
    if (!$suite_ids) {
      return 0;
    }
    $cases = $this->entityTypeManager->getStorage('qa_test_case');
    $case_ids = $cases->getQuery()->accessCheck(FALSE)
      ->condition('suite', $suite_ids, 'IN')
      ->condition('state', 'ready')
      ->sort('weight')
      ->sort('name')
      ->sort('id')
      ->execute();
    $steps = $this->entityTypeManager->getStorage('qa_test_step');
    $results = $this->entityTypeManager->getStorage('qa_step_result');
    $created = 0;
    foreach ($cases->loadMultiple($case_ids) as $case) {
      $suite = $case->get('suite')->entity;
      /** @var \Drupal\divengine_qa\Entity\QaTestExecution $execution */
      $execution = $executions->create([
        'run' => $run->id(),
        'origin' => 'planned',
        'test_case' => $case->id(),
        'suite' => $suite?->id(),
        'suite_name' => $suite?->label(),
        'suite_weight' => (int) $suite?->get('weight')->value,
        'case_weight' => (int) $case->get('weight')->value,
        'weight' => (int) $case->get('weight')->value,
        'snapshot_name' => $case->get('name')->value,
        'snapshot_code' => $case->get('code')->value,
        'snapshot_description' => $case->get('description')->value,
        'snapshot_preconditions' => $case->get('preconditions')->value,
        'snapshot_test_data' => $case->get('test_data')->value,
        'snapshot_acceptance_criteria' => $case->get('acceptance_criteria')->value,
        'snapshot_postconditions' => $case->get('postconditions')->value,
      ]);
      $execution->saveTrusted();
      $step_ids = $steps->getQuery()->accessCheck(FALSE)->condition('case', $case->id())->sort('weight')->sort('id')->execute();
      foreach ($steps->loadMultiple($step_ids) as $step) {
        $results->create([
          'execution' => $execution->id(),
          'test_step' => $step->id(),
          'weight' => (int) $step->get('weight')->value,
          'action' => $step->get('name')->value,
          'expected_result' => $step->get('expected_result')->value,
          'snapshot_skippable' => (bool) $step->get('is_skippable')->value,
        ])->saveTrusted();
      }
      $created++;
    }
    if ($created) {
      $run->set('snapshot_date', $this->time->getRequestTime());
    }
    $this->metrics->refreshRun($run);
    return $created;
  }

}
