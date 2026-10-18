<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\user\UserInterface;

/**
 * The lifecycle of test runs and who may move it.
 *
 * Draft → In Progress (Start, by the run's Tester or a Lead) → Completed
 * (automatically with the last result, or by a Lead on a reopened run);
 * Draft or In Progress → Cancelled (a Lead); In Progress → Draft (a Lead,
 * before any result); Completed → In Progress (Reopen, a Manager, with a
 * reason). Completed and Cancelled runs are historical evidence.
 */
class QaRunWorkflow {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected LockBackendInterface $lock,
    protected QaMetrics $metrics,
    protected QaSnapshot $snapshot,
    protected QaLogger $logger,
  ) {}

  public function start(QaTestRun $run): void {
    if ($run->getState() !== 'draft') {
      throw new QaException((string) $this->t('Only a Draft Test Run can be started.'));
    }
    if (!QaPermissions::isLead($this->currentUser) && !($this->isTester($run) && QaPermissions::isTester($this->currentUser))) {
      throw new QaException((string) $this->t('Only the Tester assigned to this Run or a QA Lead can start it.'));
    }
    if (!$this->executions($run)) {
      throw new QaException((string) $this->t("The Test Run '@run' has no executions and cannot be started. Mark the Test Cases Ready and generate the executions first.", ['@run' => $run->label()]));
    }
    $run->setStateThroughTransition('in_progress');
    $run->set('start_date', $this->time->getRequestTime());
    $run->saveTrusted();
    $run->endTransition();
  }

  /**
   * Completes a run by hand (a Lead, typically for a reopened run).
   */
  public function complete(QaTestRun $run): void {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can complete a Test Run.'));
    }
    if ($run->getState() === 'completed') {
      return;
    }
    if ($blocker = $this->completionBlockers($run)) {
      throw new QaException($blocker);
    }
    $this->markCompleted($run, FALSE);
  }

  /**
   * Completes an In Progress run when nothing holds it open.
   *
   * Called after every result change. Failed and Blocked results count as
   * finished: completed means the execution work is done, not that the
   * system was accepted. A lock serializes concurrent last results.
   */
  public function autoComplete(QaTestRun $run): void {
    $lock = 'qa_run:' . $run->id();
    if (!$this->lock->acquire($lock)) {
      $this->lock->wait($lock);
      if (!$this->lock->acquire($lock)) {
        return;
      }
    }
    try {
      $run = $this->entityTypeManager->getStorage('qa_test_run')->loadUnchanged($run->id());
      if ($run->getState() === 'in_progress' && $this->completionBlockers($run) === '') {
        $this->markCompleted($run, TRUE);
      }
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * The first reason a run cannot complete, or ''.
   */
  public function completionBlockers(QaTestRun $run): string {
    $executions = $this->executions($run);
    if (!$executions) {
      return (string) $this->t('A Test Run without executions cannot be completed.');
    }
    if (array_filter($executions, fn (QaTestExecution $e) => $e->getResult() === 'pending')) {
      return (string) $this->t('A Run cannot be completed while Test Executions remain Pending.');
    }
    foreach ($executions as $execution) {
      if ($execution->isCaseSkipped()) {
        continue;
      }
      foreach ($execution->getStepResults() as $step) {
        if ($step->getResult() === 'pending') {
          return (string) $this->t('A Run cannot be completed while steps remain Pending. Skip optional steps with a reason.');
        }
      }
    }
    foreach ($executions as $execution) {
      foreach ($execution->getStepResults() as $step) {
        if ($step->getResult() === 'skipped' && !$step->isOptional()) {
          return (string) $this->t('A Run cannot be completed while a mandatory step is Skipped. Correct it to Passed, Failed or Blocked with a reason.');
        }
      }
    }
    return '';
  }

  public function cancel(QaTestRun $run): void {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can cancel a Test Run.'));
    }
    if ($run->isHistorical()) {
      throw new QaException((string) $this->t('A Completed or Cancelled Run is historical evidence and cannot be modified. Reopen a Completed Run before making corrections.'));
    }
    $run->setStateThroughTransition('cancelled');
    $run->saveTrusted();
    $run->endTransition();
  }

  public function resetToDraft(QaTestRun $run): void {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can reset a Test Run.'));
    }
    if ($run->getState() !== 'in_progress') {
      throw new QaException((string) $this->t('Only an In Progress Test Run can be reset to Draft.'));
    }
    if ($this->hasRecordedResults($run)) {
      throw new QaException((string) $this->t("The Test Run '@run' already contains recorded results and cannot be reset to Draft. Cancel it instead.", ['@run' => $run->label()]));
    }
    $run->setStateThroughTransition('draft');
    $run->set('start_date', NULL)->set('end_date', NULL);
    $run->saveTrusted();
    $run->endTransition();
  }

  public function reopen(QaTestRun $run, string $reason): void {
    if (!QaPermissions::isManager($this->currentUser)) {
      throw new QaException((string) $this->t('Only QA Managers can reopen a Completed Test Run.'));
    }
    if ($run->getState() !== 'completed') {
      throw new QaException((string) $this->t('Only a Completed Test Run can be reopened.'));
    }
    $reason = trim($reason);
    if ($reason === '') {
      throw new QaException((string) $this->t('A reason is required to reopen a Completed Test Run.'));
    }
    $now = $this->time->getRequestTime();
    $run->setStateThroughTransition('in_progress');
    $run->set('end_date', NULL)
      ->set('last_reopened_by', $this->currentUser->id())
      ->set('last_reopened_at', $now)
      ->set('last_reopen_reason', $reason)
      ->set('reopen_count', (int) $run->get('reopen_count')->value + 1)
      ->set('is_auto_completed', FALSE);
    $run->saveTrusted();
    $run->endTransition();
    $this->logger->log($run, $this->t('<strong>Test Run reopened</strong><br>Manager: @name<br>Timestamp: @ts<br>Reason: @reason', [
      '@name' => $this->currentUser->getDisplayName(),
      '@ts' => date('Y-m-d H:i:s', $now),
      '@reason' => $reason,
    ]));
  }

  public function reassign(QaTestRun $run, ?UserInterface $tester, string $reason): void {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can reassign a Test Run.'));
    }
    $reason = trim($reason);
    if ($reason === '') {
      throw new QaException((string) $this->t('A reason is required to reassign a Test Run.'));
    }
    if ($tester && !QaPermissions::isTester($tester)) {
      throw new QaException((string) $this->t('Select a QA Tester as the new assignee.'));
    }
    if ($tester && !$run->get('plan')->entity?->access('view', $tester)) {
      throw new QaException((string) $this->t('@name cannot access the Development @development.', ['@name' => $tester->getDisplayName(), '@development' => $run->get('development')->entity?->label()]));
    }
    $this->checkTesterChange($run);
    $old = $run->get('tester')->entity;
    $run->set('tester', $tester?->id());
    $run->saveTrusted();
    $this->logger->log($run, $this->t('<strong>Test Run reassigned</strong><br>From: @old<br>To: @new<br>By: @actor<br>Timestamp: @ts<br>Reason: @reason', [
      '@old' => $old?->getDisplayName() ?? (string) $this->t('Unassigned'),
      '@new' => $tester?->getDisplayName() ?? (string) $this->t('Unassigned'),
      '@actor' => $this->currentUser->getDisplayName(),
      '@ts' => date('Y-m-d H:i:s', $this->time->getRequestTime()),
      '@reason' => $reason,
    ]));
  }

  /**
   * The rules every change of Tester follows.
   */
  public function checkTesterChange(QaTestRun $run): void {
    if (!in_array($run->getState(), ['draft', 'in_progress'], TRUE)) {
      throw new QaException((string) $this->t("The Test Run '@run' is @state and cannot be reassigned.", ['@run' => $run->label(), '@state' => $run->getStateLabel()]));
    }
    if ($this->hasTesterWork($run)) {
      throw new QaException((string) $this->t("The Test Run '@run' already contains recorded work (results, corrections, scenarios or evidence), so its Tester can no longer change.", ['@run' => $run->label()]));
    }
  }

  /**
   * A Tester creates a Draft run for themselves from an Active plan.
   */
  public function createSelfServiceRun(QaTestPlan $plan, string $name = '', ?string $test_url = NULL): QaTestRun {
    if (!QaPermissions::isTester($this->currentUser)) {
      throw new QaException((string) $this->t('Only QA Testers can create their own Test Runs.'));
    }
    if (!$plan->access('view')) {
      throw new QaException((string) $this->t('Select the Test Plan of the new Test Run.'));
    }
    if ($plan->getState() !== 'active') {
      throw new QaException((string) $this->t("The Test Plan '@plan' is not Active. Test Runs can only be created from Active Test Plans.", ['@plan' => $plan->label()]));
    }
    if (!$this->metrics->plan($plan)['ready_cases']) {
      throw new QaException((string) $this->t("The Test Plan '@plan' has no Ready Test Cases to execute. Ask your QA Lead to prepare the Plan.", ['@plan' => $plan->label()]));
    }
    $name = trim($name) ?: $plan->get('name')->value . ' - ' . $this->currentUser->getDisplayName() . ' - ' . date('Y-m-d', $this->time->getRequestTime());
    $values = [
      'plan' => $plan->id(),
      'name' => $name,
      'tester' => $this->currentUser->id(),
      'is_self_service' => TRUE,
    ];
    if ($test_url !== NULL) {
      $values['test_url'] = $test_url;
    }
    /** @var \Drupal\divengine_qa\Entity\QaTestRun $run */
    $run = $this->entityTypeManager->getStorage('qa_test_run')->create($values);
    $run->saveTrusted();
    $this->logger->log($run, $this->t('Test Run created by @user for themselves from the Active Test Plan @plan.', ['@user' => $this->currentUser->getDisplayName(), '@plan' => $plan->label()]));
    return $run;
  }

  /**
   * One Draft run per Tester, each with its own snapshot.
   *
   * @param \Drupal\user\UserInterface[] $testers
   *
   * @return \Drupal\divengine_qa\Entity\QaTestRun[]
   */
  public function createRunsForTesters(QaTestPlan $plan, array $testers, string $prefix): array {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can assign a Test Run.'));
    }
    if (!$this->metrics->plan($plan)['ready_cases']) {
      throw new QaException((string) $this->t('The Test Plan has no Ready Test Cases to execute.'));
    }
    if ($not_testers = array_filter($testers, fn (UserInterface $user) => !QaPermissions::isTester($user))) {
      throw new QaException((string) $this->t('These users are not QA Testers: @names', ['@names' => implode(', ', array_map(fn ($user) => $user->getDisplayName(), $not_testers))]));
    }
    $runs = [];
    foreach ($testers as $tester) {
      $run = $this->entityTypeManager->getStorage('qa_test_run')->create([
        'plan' => $plan->id(),
        'name' => trim($prefix) . ' - ' . $tester->getDisplayName(),
        'tester' => $tester->id(),
      ]);
      $run->saveTrusted();
      $runs[] = $run;
    }
    return $runs;
  }

  /**
   * Builds the snapshot of a Draft run that has none (a Lead).
   */
  public function generateExecutions(QaTestRun $run): int {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can generate the executions of a Test Run.'));
    }
    if ($run->getState() !== 'draft' || $this->executions($run)) {
      return 0;
    }
    return $this->snapshot->build($run);
  }

  /**
   * Adds an ad-hoc scenario discovered during the run, with one step.
   */
  public function addScenario(QaTestRun $run, string $title, string $description, string $expected, string $preconditions = '', string $test_data = ''): QaTestExecution {
    if (!QaPermissions::isLead($this->currentUser) && !($this->isTester($run) && QaPermissions::isTester($this->currentUser))) {
      throw new QaException((string) $this->t('Only the Tester assigned to this Run or a QA Lead can add a scenario.'));
    }
    if ($run->getState() !== 'in_progress') {
      throw new QaException((string) $this->t("Scenarios can only be added while the Test Run '@run' is In Progress.", ['@run' => $run->label()]));
    }
    $missing = [];
    foreach (['Scenario Title' => $title, 'Description / Objective' => $description, 'Expected Result' => $expected] as $label => $value) {
      if (trim($value) === '') {
        $missing[] = $label;
      }
    }
    if ($missing) {
      throw new QaException((string) $this->t('Please fill in: @labels.', ['@labels' => implode(', ', $missing)]));
    }
    $executions = $this->executions($run);
    $ad_hoc = array_filter($executions, fn (QaTestExecution $e) => $e->isAdHoc());
    $suite_weight = $ad_hoc
      ? (int) reset($ad_hoc)->get('suite_weight')->value
      : max(array_merge([0], array_map(fn ($e) => (int) $e->get('suite_weight')->value, $executions))) + 1;
    $n = count($ad_hoc) + 1;
    /** @var \Drupal\divengine_qa\Entity\QaTestExecution $execution */
    $execution = $this->entityTypeManager->getStorage('qa_test_execution')->create([
      'run' => $run->id(),
      'origin' => 'ad_hoc',
      'suite_name' => (string) $this->t('Ad-hoc Scenarios'),
      'suite_weight' => $suite_weight,
      'case_weight' => $n * 10,
      'weight' => $n * 10,
      'snapshot_code' => sprintf('AH-%02d', $n),
      'snapshot_name' => trim($title),
      'snapshot_description' => trim($description),
      'snapshot_acceptance_criteria' => trim($expected),
      'snapshot_preconditions' => trim($preconditions) ?: NULL,
      'snapshot_test_data' => trim($test_data) ?: NULL,
      'discovered_by' => $this->currentUser->id(),
      'discovered_on' => $this->time->getRequestTime(),
    ]);
    $execution->saveTrusted();
    $this->entityTypeManager->getStorage('qa_step_result')->create([
      'execution' => $execution->id(),
      'weight' => 10,
      'action' => trim($title),
      'expected_result' => trim($expected),
      'snapshot_skippable' => FALSE,
    ])->saveTrusted();
    $this->logger->log($execution, $this->t('Ad-hoc scenario <strong>@title</strong> was discovered during this Run.', ['@title' => trim($title)]));
    $this->logger->log($run, $this->t('@exec: ad-hoc scenario <strong>@title</strong> was discovered during this Run.', ['@exec' => $execution->label(), '@title' => trim($title)]));
    $this->metrics->refreshRun($this->entityTypeManager->getStorage('qa_test_run')->loadUnchanged($run->id()));
    return $execution;
  }

  /**
   * Whether anything was recorded in the run.
   */
  public function hasRecordedResults(QaTestRun $run): bool {
    foreach ($this->executions($run) as $execution) {
      if ($execution->isAdHoc() || $execution->getResult() !== 'pending') {
        return TRUE;
      }
      foreach ($execution->getStepResults() as $step) {
        if ($step->getResult() !== 'pending') {
          return TRUE;
        }
      }
    }
    return (bool) $this->entityTypeManager->getStorage('qa_result_correction')->getQuery()
      ->accessCheck(FALSE)->condition('run', $run->id())->count()->execute();
  }

  /**
   * Whether the Tester already worked on the run.
   */
  public function hasTesterWork(QaTestRun $run): bool {
    if ($this->hasRecordedResults($run)) {
      return TRUE;
    }
    foreach ($this->executions($run) as $execution) {
      if (!$execution->get('executed_by')->isEmpty() || !$execution->get('evidence')->isEmpty()) {
        return TRUE;
      }
      foreach ($execution->getStepResults() as $step) {
        if (!$step->get('executed_by')->isEmpty() || !$step->get('evidence')->isEmpty()) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * The executions of a run in execution order.
   *
   * @return \Drupal\divengine_qa\Entity\QaTestExecution[]
   */
  public function executions(QaTestRun $run): array {
    $storage = $this->entityTypeManager->getStorage('qa_test_execution');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('run', $run->id())
      ->sort('suite_weight')
      ->sort('case_weight')
      ->sort('weight')
      ->sort('id')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  protected function markCompleted(QaTestRun $run, bool $automatic): void {
    $run->setStateThroughTransition('completed');
    $run->set('end_date', $this->time->getRequestTime());
    if ($automatic) {
      $run->set('is_auto_completed', TRUE);
    }
    $run->saveTrusted();
    $run->endTransition();
  }

  protected function isTester(QaTestRun $run): bool {
    return $this->currentUser->isAuthenticated() && (int) $run->get('tester')->target_id === (int) $this->currentUser->id();
  }

}
