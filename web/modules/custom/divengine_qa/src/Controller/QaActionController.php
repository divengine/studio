<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\QaException;
use Drupal\divengine_qa\QaPermissions;
use Drupal\divengine_qa\QaResultRecorder;
use Drupal\divengine_qa\QaRunWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One-click QA actions and navigation between executions.
 */
class QaActionController extends ControllerBase {

  public function __construct(
    protected QaRunWorkflow $runs,
    protected QaResultRecorder $results,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_qa.runs'), $container->get('divengine_qa.results'));
  }

  /**
   * Start, Complete, Cancel, Reset to Draft or Generate Executions.
   */
  public function run(QaTestRun $qa_test_run, string $action): RedirectResponse {
    try {
      match ($action) {
        'start' => $this->runs->start($qa_test_run),
        'complete' => $this->runs->complete($qa_test_run),
        'cancel' => $this->runs->cancel($qa_test_run),
        'reset' => $this->runs->resetToDraft($qa_test_run),
        'generate' => ($count = $this->runs->generateExecutions($qa_test_run))
          ? $this->messenger()->addStatus($this->formatPlural($count, 'Generated 1 execution.', 'Generated @count executions.'))
          : $this->messenger()->addWarning($this->t('No executions were generated: the Test Plan has no Ready Test Cases. The Run stays a Draft without a snapshot.')),
      };
    }
    catch (QaException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    return new RedirectResponse($qa_test_run->toUrl()->toString());
  }

  /**
   * Pass: recorded at once on a pending step or step-less execution;
   * otherwise it opens the result dialog (a correction needs a reason).
   */
  public function pass(string $type, string $id): RedirectResponse {
    $storage = $this->entityTypeManager()->getStorage($type === 'step' ? 'qa_step_result' : 'qa_test_execution');
    $record = $storage->load($id);
    $execution = $type === 'step' ? $record?->get('execution')->entity : $record;
    if (!$record || !$execution) {
      throw new NotFoundHttpException();
    }
    if ($record instanceof QaTestExecution && $record->isDerived()) {
      $this->messenger()->addError($this->t('This execution has steps: record each step result and the execution result is calculated automatically.'));
    }
    elseif ($record->getResult() === 'pending') {
      try {
        $this->results->record($record, 'passed');
      }
      catch (QaException $e) {
        $this->messenger()->addError($e->getMessage());
      }
    }
    else {
      return new RedirectResponse(Url::fromRoute('divengine_qa.record_result', ['type' => $type, 'id' => $id], ['query' => ['state' => 'passed']])->toString());
    }
    return new RedirectResponse($execution->toUrl()->toString());
  }

  /**
   * Access to the result, evidence and pass routes of a step or execution.
   *
   * The record must be visible and the user a Tester; the services check
   * the run state and the assignment when the action is taken.
   */
  public function recordAccess(string $type, string $id, AccountInterface $account): AccessResultInterface {
    $record = $this->entityTypeManager()->getStorage($type === 'step' ? 'qa_step_result' : 'qa_test_execution')->load($id);
    if (!$record) {
      return AccessResult::neutral();
    }
    return $record->access('view', $account, TRUE)
      ->andIf(AccessResult::allowedIf(QaPermissions::isTester($account))->cachePerPermissions());
  }

  /**
   * Previous, Next or Next Pending execution of the same run, cyclic.
   */
  public function navigate(QaTestExecution $qa_test_execution, string $direction): RedirectResponse {
    $executions = array_values($this->runs->executions($qa_test_execution->get('run')->entity));
    $position = 0;
    foreach ($executions as $i => $execution) {
      if ((string) $execution->id() === (string) $qa_test_execution->id()) {
        $position = $i;
      }
    }
    $count = count($executions);
    $target = NULL;
    if ($direction === 'pending') {
      for ($step = 1; $step < $count; $step++) {
        $candidate = $executions[($position + $step) % $count];
        if ($candidate->getResult() === 'pending') {
          $target = $candidate;
          break;
        }
      }
      if (!$target) {
        $this->messenger()->addStatus($this->t('No pending executions: every other execution of this Run already has a result.'));
        $target = $qa_test_execution;
      }
    }
    else {
      $target = $executions[($position + ($direction === 'previous' ? -1 : 1) + $count) % $count];
    }
    return new RedirectResponse($target->toUrl()->toString());
  }

}
