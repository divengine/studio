<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_qa\Entity\QaIssue;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\file\FileRepositoryInterface;

/**
 * Reporting QA Issues from test executions ("Create or Link QA Issue").
 *
 * The issue is prefilled from the execution: its priority from the case
 * priority, its title from the failed step or the observed behaviour, and
 * a description that holds everything needed to reproduce the failure.
 */
class QaIssues {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected QaLogger $logger,
  ) {}

  /**
   * Checks the execution and step the wizard was opened from.
   *
   * @throws \Drupal\divengine_qa\QaException
   */
  public function checkTarget(?QaTestExecution $execution, ?QaStepResult $step = NULL): void {
    if (!$execution) {
      throw new QaException((string) $this->t('Open the QA Issue wizard from a Test Execution or Step Result.'));
    }
    if ($step && (string) $step->get('execution')->target_id !== (string) $execution->id()) {
      throw new QaException((string) $this->t('The selected step does not belong to this execution.'));
    }
    if (!$execution->access('view', $this->currentUser)) {
      throw new QaException((string) $this->t('You cannot report QA Issues on this execution.'));
    }
  }

  /**
   * The failed step the issue is about: the given one, else the first.
   */
  public function primaryFailedStep(QaTestExecution $execution, ?QaStepResult $step = NULL): ?QaStepResult {
    if ($step && (string) $step->get('execution')->target_id === (string) $execution->id() && $step->getResult() === 'failed') {
      return $step;
    }
    foreach ($execution->getStepResults() as $candidate) {
      if ($candidate->getResult() === 'failed') {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Issue priority from the case priority, or from the result.
   */
  public function defaultPriority(QaTestExecution $execution): string {
    $name = mb_strtolower((string) $execution->get('test_case')->entity?->get('priority')->entity?->label());
    return match (TRUE) {
      in_array($name, ['urgent', 'critical', 'blocker'], TRUE) => '3',
      in_array($name, ['high', 'major'], TRUE) => '2',
      in_array($name, ['low', 'minor', 'trivial'], TRUE) => '0',
      in_array($execution->getResult(), ['failed', 'blocked'], TRUE) => '2',
      default => '1',
    };
  }

  public function defaultTitle(QaTestExecution $execution, ?QaStepResult $failed): string {
    $code = (string) $execution->get('snapshot_code')->value;
    $title = '[QA Issue] ' . ($code !== '' ? "[$code] " : '') . $execution->get('snapshot_name')->value;
    $excerpt = fn (string $text) => Unicode::truncate(preg_replace('/\s*\R\s*/', ' ', trim($text)), 60);
    if ($failed) {
      $what = (string) ($failed->get('actual_result')->value ?: $failed->get('action')->value ?: 'Step failure');
      return $title . ' - Step #' . $failed->position() . ': ' . $excerpt($what);
    }
    if ($actual = (string) $execution->get('actual_result')->value) {
      return $title . ' - ' . $excerpt($actual);
    }
    return $title;
  }

  /**
   * Evidence of the execution and of its failed step(s).
   *
   * @return \Drupal\file\FileInterface[]
   */
  public function relevantEvidence(QaTestExecution $execution, ?QaStepResult $failed): array {
    $holders = [$execution];
    if ($failed) {
      $holders[] = $failed;
    }
    else {
      $holders = array_merge($holders, array_filter($execution->getStepResults(), fn (QaStepResult $s) => $s->getResult() === 'failed'));
    }
    $files = [];
    foreach ($holders as $holder) {
      foreach ($holder->get('evidence') as $item) {
        if ($item->entity) {
          $files[$item->entity->id()] = $item->entity;
        }
      }
    }
    return $files;
  }

  /**
   * The generated description: everything needed to reproduce the issue.
   *
   * Every value is escaped; only the tags of the QA Issue text format are
   * used.
   */
  public function defaultDescription(QaTestExecution $execution, ?QaStepResult $failed): string {
    $e = fn ($value) => Html::escape((string) $value);
    $text = fn ($value) => nl2br(Html::escape(trim((string) $value)));
    $none = (string) $this->t('None specified');
    $run = $execution->get('run')->entity;
    $plan = $execution->get('plan')->entity;
    $ad_hoc = $execution->isAdHoc();
    $code = (string) $execution->get('snapshot_code')->value;
    $results = QaVocabulary::results();
    $result = mb_strtoupper((string) $results[$execution->getResult()]);
    $date = $execution->get('execution_date')->value
      ? \Drupal::service('date.formatter')->format((int) $execution->get('execution_date')->value, 'short')
      : (string) $this->t('Not recorded');

    $html = '<h3>' . $this->t('QA Issue Report') . '</h3>';
    $row = fn ($l1, $v1, $l2, $v2) => '<tr><th>' . $e($l1) . '</th><td>' . $v1 . '</td><th>' . $e($l2) . '</th><td>' . $v2 . '</td></tr>';
    $html .= '<table><tbody>'
      . $row($ad_hoc ? $this->t('Ad-hoc Scenario') : $this->t('Test Case'), $e(($code !== '' ? "[$code] " : '') . $execution->get('snapshot_name')->value), $this->t('Development'), $e($execution->get('development')->entity?->label()))
      . $row($this->t('Environment'), $e($plan?->get('environment')->entity?->label()), $this->t('Execution Result'), '<strong>' . $e($result) . '</strong>')
      . $row($this->t('Test Plan'), $e($plan?->label()), $this->t('Test Run'), $e($run?->label()))
      . $row($this->t('Tester'), $e($run?->get('tester')->entity?->getDisplayName()), $this->t('Recorded On'), $e($date))
      . '</tbody></table>';

    if ($failed || $execution->get('actual_result')->value) {
      $html .= '<h4>' . $this->t('Failure Details') . '</h4><blockquote>';
      if ($failed) {
        $html .= '<p><strong>' . $this->t('Failed Step #@n:', ['@n' => $failed->position()]) . '</strong> ' . $text($failed->get('action')->value) . '</p>'
          . '<p><strong>' . $this->t('Expected Outcome:') . '</strong> ' . $text($failed->get('expected_result')->value) . '</p>'
          . '<p><strong>' . $this->t('Observed Behavior:') . '</strong> ' . ($failed->get('actual_result')->value ? $text($failed->get('actual_result')->value) : $e($this->t('Not specified'))) . '</p>';
        if ($failed->get('comments')->value) {
          $html .= '<p><strong>' . $this->t('Tester Notes:') . '</strong> ' . $text($failed->get('comments')->value) . '</p>';
        }
      }
      else {
        $html .= '<p><strong>' . $this->t('Observed Behavior:') . '</strong> ' . $text($execution->get('actual_result')->value) . '</p>';
      }
      $html .= '</blockquote>';
    }
    $html .= '<h4>' . $this->t('Preconditions') . '</h4><p>' . ($execution->get('snapshot_preconditions')->value ? $text($execution->get('snapshot_preconditions')->value) : $e($none)) . '</p>';
    $html .= '<h4>' . $this->t('Test Data') . '</h4><p>' . ($execution->get('snapshot_test_data')->value ? $text($execution->get('snapshot_test_data')->value) : $e($none)) . '</p>';

    $html .= '<h4>' . $this->t('Steps to Reproduce &amp; Execution Results') . '</h4>';
    $steps = $execution->getStepResults();
    if ($steps) {
      $html .= '<table><thead><tr><th>#</th><th>' . $this->t('Step Action') . '</th><th>' . $this->t('Expected Result') . '</th><th>' . $this->t('Status') . '</th><th>' . $this->t('Actual Result / Notes') . '</th></tr></thead><tbody>';
      foreach ($steps as $step) {
        $notes = $step->get('actual_result')->value ?: $step->get('result_reason')->value ?: $step->get('comments')->value ?: '-';
        $status = $e(mb_strtoupper((string) $results[$step->getResult()]));
        $html .= '<tr><td>' . $step->position() . '</td><td>' . $text($step->get('action')->value) . '</td><td>' . $text($step->get('expected_result')->value) . '</td><td>'
          . ($step->getResult() === 'failed' ? '<strong>' . $status . '</strong>' : $status) . '</td><td>' . $text($notes) . '</td></tr>';
      }
      $html .= '</tbody></table>';
    }
    else {
      $html .= '<p>' . $this->t('No individual steps recorded for this execution.') . '</p>';
    }
    if ($execution->get('comments')->value) {
      $html .= '<h4>' . $this->t('Execution Notes') . '</h4><p>' . $text($execution->get('comments')->value) . '</p>';
    }
    if ($ad_hoc) {
      $html .= '<h4>' . $this->t('Objective') . '</h4><p>' . ($execution->get('snapshot_description')->value ? $text($execution->get('snapshot_description')->value) : $e($none)) . '</p>';
    }
    $html .= '<h4>' . ($ad_hoc ? $this->t('Expected Result') : $this->t('Acceptance Criteria')) . '</h4><p>'
      . ($execution->get('snapshot_acceptance_criteria')->value ? $text($execution->get('snapshot_acceptance_criteria')->value) : $e($none)) . '</p>';
    if ($execution->get('snapshot_postconditions')->value) {
      $html .= '<h4>' . $this->t('Postconditions') . '</h4><p>' . $text($execution->get('snapshot_postconditions')->value) . '</p>';
    }
    if ($files = $this->relevantEvidence($execution, $failed)) {
      $html .= '<h4>' . $this->t('Evidence &amp; Attachments') . '</h4><ul>';
      foreach ($files as $file) {
        $html .= '<li>📎 ' . $e($file->getFilename()) . '</li>';
      }
      $html .= '</ul>';
    }
    return $html;
  }

  /**
   * Open QA Issues of the same test case: likely duplicates.
   *
   * @return \Drupal\divengine_qa\Entity\QaIssue[]
   */
  public function duplicates(QaTestExecution $execution): array {
    if ($execution->isAdHoc() || $execution->get('test_case')->isEmpty()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('qa_issue');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('development', $execution->get('development')->target_id)
      ->condition('executions.entity:qa_test_execution.test_case', $execution->get('test_case')->target_id)
      ->condition('state', QaIssue::OPEN_STATES, 'IN')
      ->sort('id', 'DESC')
      ->range(0, 20)
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Open QA Issues of the Development that an execution can be linked to.
   *
   * @return string[]
   *   Labels keyed by issue ID.
   */
  public function linkableIssues(QaTestExecution $execution): array {
    $storage = $this->entityTypeManager->getStorage('qa_issue');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('development', $execution->get('development')->target_id)
      ->condition('state', QaIssue::OPEN_STATES, 'IN')
      ->sort('id', 'DESC')
      ->execute();
    return array_map(fn (QaIssue $issue) => $issue->label(), $storage->loadMultiple($ids));
  }

  /**
   * Creates a QA Issue from an execution.
   */
  public function create(QaTestExecution $execution, ?QaStepResult $step, string $title, string $description, string $priority, bool $copy_attachments): QaIssue {
    $this->checkTarget($execution, $step);
    if (trim($title) === '') {
      throw new QaException((string) $this->t('A QA Issue title is required.'));
    }
    /** @var \Drupal\divengine_qa\Entity\QaIssue $issue */
    $issue = $this->entityTypeManager->getStorage('qa_issue')->create([
      'name' => trim($title),
      'description' => ['value' => $description, 'format' => 'qa_issue'],
      'priority' => isset(QaVocabulary::issuePriorities()[$priority]) ? $priority : '1',
      'origin' => $execution->id(),
      'development' => $execution->get('development')->target_id,
    ]);
    $this->assertValid($issue);
    $issue->save();
    if ($copy_attachments) {
      $this->copyEvidence($issue, $this->relevantEvidence($execution, $this->primaryFailedStep($execution, $step)));
    }
    $this->logger->log($execution, $this->t('QA Issue <a href=":url">@issue</a> was created from this execution.', [':url' => $issue->toUrl()->toString(), '@issue' => $issue->label()]));
    $this->logger->log($issue, $this->t('Created from QA Execution <a href=":url">@exec</a>.', [':url' => $execution->toUrl()->toString(), '@exec' => $execution->label()]));
    return $issue;
  }

  /**
   * Links an execution to an existing QA Issue.
   */
  public function link(QaTestExecution $execution, ?QaStepResult $step, ?QaIssue $issue, bool $copy_attachments): QaIssue {
    $this->checkTarget($execution, $step);
    if (!$issue) {
      throw new QaException((string) $this->t('Select the existing QA Issue to link.'));
    }
    if ((string) $issue->get('development')->target_id !== (string) $execution->get('development')->target_id) {
      throw new QaException((string) $this->t('A QA Issue can only be linked to executions of its own Development.'));
    }
    $linked = array_map('strval', array_column($issue->get('executions')->getValue(), 'target_id'));
    if (!in_array((string) $execution->id(), $linked, TRUE)) {
      $issue->get('executions')->appendItem($execution->id());
    }
    if ($issue->get('origin')->isEmpty()) {
      $issue->set('origin', $execution->id());
    }
    $this->assertValid($issue);
    $issue->save();
    if ($copy_attachments) {
      $this->copyEvidence($issue, $this->relevantEvidence($execution, $this->primaryFailedStep($execution, $step)));
    }
    $this->logger->log($execution, $this->t('Existing QA Issue <a href=":url">@issue</a> was linked to this execution.', [':url' => $issue->toUrl()->toString(), '@issue' => $issue->label()]));
    $this->logger->log($issue, $this->t('QA Execution <a href=":url">@exec</a> was linked to this QA Issue.', [':url' => $execution->toUrl()->toString(), '@exec' => $execution->label()]));
    return $issue;
  }

  /**
   * The QA Issues linked to an execution.
   *
   * @return \Drupal\divengine_qa\Entity\QaIssue[]
   */
  public function forExecution(QaTestExecution $execution, bool $access_check = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('qa_issue');
    return $storage->loadMultiple($storage->getQuery()->accessCheck($access_check)->condition('executions', $execution->id())->sort('id')->execute());
  }

  /**
   * Copies evidence files to the issue, skipping files it already holds.
   *
   * @param \Drupal\file\FileInterface[] $files
   */
  protected function copyEvidence(QaIssue $issue, array $files): void {
    $held = [];
    foreach ($issue->get('evidence') as $item) {
      if ($item->entity) {
        $held[$item->entity->getFilename() . ':' . @hash_file('sha256', $item->entity->getFileUri())] = TRUE;
      }
    }
    $copied = FALSE;
    foreach ($files as $file) {
      $key = $file->getFilename() . ':' . @hash_file('sha256', $file->getFileUri());
      if (isset($held[$key]) || !file_exists($file->getFileUri())) {
        continue;
      }
      $directory = 'private://qa/issues/' . $issue->id();
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
      $copy = $this->fileRepository->copy($file, $directory . '/' . $file->getFilename(), FileExists::Rename);
      $issue->get('evidence')->appendItem($copy->id());
      $held[$key] = TRUE;
      $copied = TRUE;
    }
    if ($copied) {
      $issue->save();
    }
  }

  /**
   * @throws \Drupal\divengine_qa\QaException
   */
  protected function assertValid(QaIssue $issue): void {
    $violations = $issue->validate();
    if ($violations->count()) {
      throw new QaException((string) $violations[0]->getMessage());
    }
  }

}
