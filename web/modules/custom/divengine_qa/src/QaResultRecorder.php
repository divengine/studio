<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * Records results on executions and steps, the only way results change.
 *
 * - Results are recorded only while the run is In Progress, by its Tester or
 *   a QA Lead.
 * - Failed needs the observed behaviour; Blocked and Skipped need a reason;
 *   only Optional steps can be Skipped.
 * - Changing a recorded result is a correction: it needs a reason and leaves
 *   an immutable correction row.
 * - An execution with steps takes its result from them: Failed beats
 *   Blocked, Blocked beats Pending, otherwise Passed (Skipped when every step
 *   was skipped).
 * - A run completes by itself when nothing is left Pending.
 */
class QaResultRecorder {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected QaMetrics $metrics,
    protected QaLogger $logger,
    protected QaRunWorkflow $runs,
  ) {}

  /**
   * Records a result on a step or a step-less (or case-skipped) execution.
   *
   * @param \Drupal\divengine_qa\Entity\QaTestExecution|\Drupal\divengine_qa\Entity\QaStepResult $record
   *   The execution or step result.
   * @param string $state
   *   pending, passed, failed, blocked or skipped.
   * @param array{actual_result?: string, result_reason?: string, comments?: string} $values
   *   Observations; omitted keys keep their value.
   * @param string|null $correction_reason
   *   Why a recorded result changes; required for a correction.
   *
   * @throws \Drupal\divengine_qa\QaException
   */
  public function record(QaTestExecution|QaStepResult $record, string $state, array $values = [], ?string $correction_reason = NULL): void {
    $execution = $record instanceof QaTestExecution ? $record : $record->get('execution')->entity;
    $run = $execution->get('run')->entity;
    $this->checkCanRecord($record, $run);
    if (!isset(QaVocabulary::results()[$state])) {
      throw new QaException((string) $this->t('Unknown result %state.', ['%state' => $state]));
    }

    if ($record instanceof QaStepResult) {
      if ($execution->isCaseSkipped()) {
        throw new QaException((string) $this->t('This Test Case was skipped as a whole, so its steps take no results. Correct the execution result to Pending first to record its steps.'));
      }
      if ($state === 'skipped' && !$record->isOptional()) {
        throw new QaException((string) $this->t("The step '@action' is mandatory and cannot be Skipped. Mark it Blocked with a reason if a prerequisite is missing, or leave it Pending until it is executed.", ['@action' => $record->get('action')->value]));
      }
    }
    elseif ($record->isCaseSkipped()) {
      if ($state !== 'pending') {
        throw new QaException((string) $this->t('A skipped Test Case can only be corrected back to Pending; its steps are then recorded one by one.'));
      }
    }
    elseif ($record->isDerived()) {
      $derived = $this->derive($record);
      if ($state !== $derived) {
        throw new QaException((string) $this->t('@exec: the result of an execution with steps is derived from its step results (@derived) and cannot be set to @new. Record the result on the steps.', [
          '@exec' => $record->label(),
          '@derived' => QaVocabulary::results()[$derived],
          '@new' => QaVocabulary::results()[$state],
        ]));
      }
    }

    $old = $record->getResult();
    foreach (['actual_result', 'result_reason', 'comments'] as $field) {
      if (array_key_exists($field, $values)) {
        $record->set($field, $values[$field]);
      }
    }
    $this->checkJustification($record, $state);
    $is_correction = $old !== 'pending' && $old !== $state;
    $correction_reason = trim((string) $correction_reason);
    if ($is_correction && $correction_reason === '') {
      throw new QaException((string) $this->t('@record: changing a recorded @old result requires a correction reason.', [
        '@record' => $record->label(),
        '@old' => QaVocabulary::results()[$old],
      ]));
    }

    $this->stamp($record, $state);
    if ($is_correction) {
      $record->set('correction_reason', $correction_reason);
    }
    $undo_case_skip = $record instanceof QaTestExecution && $record->isCaseSkipped() && $state === 'pending';
    if ($undo_case_skip) {
      $record->set('is_case_skipped', FALSE);
    }
    $record->saveTrusted();
    if ($is_correction) {
      $this->correction($execution, $record instanceof QaStepResult ? $record : NULL, $old, $state, $correction_reason);
    }

    if ($record instanceof QaStepResult) {
      $this->syncExecution($execution, $is_correction ? $correction_reason : NULL);
    }
    elseif ($undo_case_skip) {
      $this->syncExecution($execution, NULL);
    }
    $this->afterResults($run, $execution);
  }

  /**
   * Blocks or skips every pending step of an execution with one reason.
   *
   * @throws \Drupal\divengine_qa\QaException
   */
  public function recordRemaining(QaTestExecution $execution, string $state, string $reason): void {
    $run = $execution->get('run')->entity;
    $this->checkCanRecord($execution, $run);
    if (!in_array($state, ['blocked', 'skipped'], TRUE)) {
      throw new QaException((string) $this->t('Only Blocked or Skipped can be applied to pending steps.'));
    }
    $pending = array_filter($execution->getStepResults(), fn (QaStepResult $step) => $step->getResult() === 'pending');
    if (!$pending) {
      throw new QaException((string) $this->t('This execution has no pending steps left.'));
    }
    if ($state === 'skipped' && ($mandatory = array_filter($pending, fn (QaStepResult $step) => !$step->isOptional()))) {
      throw new QaException((string) $this->t('Skip Remaining Steps is only possible when every pending step is Optional. Mandatory pending steps: @actions. Mark them Blocked with a reason, or execute them.', [
        '@actions' => implode(', ', array_map(fn ($step) => $step->get('action')->value, $mandatory)),
      ]));
    }
    if (trim($reason) === '') {
      throw new QaException((string) $this->t('@record: a @state result requires a reason.', ['@record' => $execution->label(), '@state' => QaVocabulary::results()[$state]]));
    }
    foreach ($pending as $step) {
      $step->set('result_reason', $reason);
      $this->stamp($step, $state);
      $step->saveTrusted();
    }
    $this->syncExecution($execution, NULL);
    $this->afterResults($run, $execution);
  }

  /**
   * Records a whole test case as Skipped without touching its steps.
   *
   * @throws \Drupal\divengine_qa\QaException
   */
  public function skipCase(QaTestExecution $execution, string $reason): void {
    $run = $execution->get('run')->entity;
    if (!QaPermissions::isLead($this->currentUser) && !$this->isAssignedTester($run)) {
      throw new QaException((string) $this->t('Only the Tester assigned to this Run or a QA Lead can skip a Test Case.'));
    }
    if ($run->getState() !== 'in_progress') {
      throw new QaException((string) $this->t("Test Cases can only be skipped while the Test Run '@run' is In Progress.", ['@run' => $run->label()]));
    }
    if (trim($reason) === '') {
      throw new QaException((string) $this->t('A reason is required to skip a Test Case.'));
    }
    $steps = $execution->getStepResults();
    if (!$steps) {
      throw new QaException((string) $this->t('@exec has no steps: use Skip on the execution.', ['@exec' => $execution->label()]));
    }
    if ($execution->getResult() !== 'pending') {
      throw new QaException((string) $this->t('@exec already has a result. Use Correct Result to change it.', ['@exec' => $execution->label()]));
    }
    if (array_filter($steps, fn (QaStepResult $step) => $step->getResult() !== 'pending')) {
      throw new QaException((string) $this->t('@exec already has recorded step results, which are never discarded. Record the remaining steps (Block Remaining Steps if they cannot be executed) instead of skipping the whole Test Case.', ['@exec' => $execution->label()]));
    }
    $execution->set('is_case_skipped', TRUE);
    $execution->set('result_reason', trim($reason));
    $this->stamp($execution, 'skipped');
    $execution->saveTrusted();
    $this->logger->log($execution, $this->t('<strong>Test Case skipped as a whole</strong><br>Reason: @reason', ['@reason' => trim($reason)]));
    $this->afterResults($run, $execution);
  }

  /**
   * The result an execution with steps takes from them.
   */
  public function derive(QaTestExecution $execution): string {
    $steps = $execution->getStepResults();
    $states = array_map(fn (QaStepResult $step) => $step->getResult(), $steps);
    foreach (['failed', 'blocked', 'pending'] as $winner) {
      if (in_array($winner, $states, TRUE)) {
        return $winner;
      }
    }
    $skipped = array_filter($steps, fn (QaStepResult $step) => $step->getResult() === 'skipped');
    if ($skipped && (count($skipped) === count($steps) || array_filter($skipped, fn (QaStepResult $step) => !$step->isOptional()))) {
      return 'skipped';
    }
    return 'passed';
  }

  /**
   * Re-derives an execution from its steps after a step changes.
   *
   * A step correction that changes a recorded execution result leaves an
   * execution-level correction too.
   */
  protected function syncExecution(QaTestExecution $execution, ?string $step_correction_reason): void {
    $execution = $this->entityTypeManager->getStorage('qa_test_execution')->loadUnchanged($execution->id());
    if (!$execution->isDerived()) {
      return;
    }
    $old = $execution->getResult();
    $new = $this->derive($execution);
    if ($old === $new) {
      return;
    }
    $this->stamp($execution, $new);
    $execution->saveTrusted();
    if ($step_correction_reason !== NULL && $old !== 'pending') {
      $this->correction($execution, NULL, $old, $new, (string) $this->t('Result recalculated after a step correction: @reason', ['@reason' => $step_correction_reason]));
    }
  }

  /**
   * Refreshes the run counters and the case's last result, then completes
   * the run if nothing is left pending.
   */
  protected function afterResults(QaTestRun $run, QaTestExecution $execution): void {
    $run = $this->entityTypeManager->getStorage('qa_test_run')->loadUnchanged($run->id());
    $this->metrics->refreshRun($run);
    if ($case = $execution->get('test_case')->entity) {
      $this->metrics->refreshCase($case);
    }
    $this->runs->autoComplete($run);
  }

  /**
   * Who may record results: the run's Tester or a QA Lead, while In
   * Progress.
   */
  protected function checkCanRecord(QaTestExecution|QaStepResult $record, QaTestRun $run): void {
    if (!QaPermissions::isLead($this->currentUser) && !($this->isAssignedTester($run) && QaPermissions::isTester($this->currentUser))) {
      throw new QaException((string) $this->t('Only the Tester assigned to this Run or a QA Lead can record its results.'));
    }
    if ($run->getState() !== 'in_progress') {
      throw new QaException((string) $this->t("Results, observations and reasons can only be recorded while the Test Run '@run' is In Progress.", ['@run' => $run->label()]));
    }
  }

  protected function checkJustification(QaTestExecution|QaStepResult $record, string $state): void {
    if ($record instanceof QaTestExecution && $record->isDerived()) {
      return;
    }
    if ($state === 'failed' && trim((string) $record->get('actual_result')->value) === '') {
      throw new QaException((string) $this->t('@record: a Failed result requires the actual result that was observed.', ['@record' => $record->label()]));
    }
    if (in_array($state, ['blocked', 'skipped'], TRUE) && trim((string) $record->get('result_reason')->value) === '') {
      throw new QaException((string) $this->t('@record: a @state result requires a reason.', ['@record' => $record->label(), '@state' => QaVocabulary::results()[$state]]));
    }
  }

  /**
   * Sets the result and who recorded it when (cleared for Pending).
   *
   * A correction from one final result to another keeps who recorded the
   * result and when: the execution date orders the case's results, so
   * correcting an older run never makes it the case's last result. The
   * correction record holds who corrected it, when and why.
   */
  protected function stamp(QaTestExecution|QaStepResult $record, string $state): void {
    $terminal = in_array($state, QaVocabulary::TERMINAL, TRUE);
    $recorded = in_array($record->getResult(), QaVocabulary::TERMINAL, TRUE) && !$record->get('execution_date')->isEmpty();
    $record->set('state', $state);
    if ($terminal && $recorded) {
      return;
    }
    $record->set('executed_by', $terminal ? $this->currentUser->id() : NULL);
    $record->set('execution_date', $terminal ? $this->time->getRequestTime() : NULL);
  }

  protected function correction(QaTestExecution $execution, ?QaStepResult $step, string $old, string $new, string $reason): void {
    $this->entityTypeManager->getStorage('qa_result_correction')->create([
      'execution' => $execution->id(),
      'step_result' => $step?->id(),
      'level' => $step ? 'step' : 'execution',
      'step_label' => $step?->label(),
      'old_state' => $old,
      'new_state' => $new,
      'reason' => $reason,
    ])->save();
  }

  protected function isAssignedTester(QaTestRun $run): bool {
    return $this->currentUser->isAuthenticated() && (int) $run->get('tester')->target_id === (int) $this->currentUser->id();
  }

}
