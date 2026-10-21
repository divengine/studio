<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\divengine_qa\Entity\QaResultCorrection;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\QaException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C09a: changing a recorded result leaves an immutable audit trail.
 */
#[RunTestsInSeparateProcesses]
class QaResultCorrectionTest extends KernelTestBase {

  use QaFixtureTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa'];

  protected function setUp(): void {
    parent::setUp();
    $this->installQaFixture();
  }

  /**
   * Asserts that a call is refused by the QA services with this message.
   */
  protected function assertRefused(callable $call, string $message): void {
    try {
      $call();
      $this->fail("Expected refusal: $message");
    }
    catch (QaException $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }
  }

  protected function recorder() {
    return $this->container->get('divengine_qa.results');
  }

  public function testCorrectionsNeedAReasonAndLeaveAnImmutableTrail(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $manager = $this->qaUser('manager');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester, $manager]));
    $suite = $this->qaSuite($plan);
    $this->qaCase($suite, 'With steps', ['Open' => 'Opens', 'Save' => 'Saved'], 'ready', 1);
    $this->qaCase($suite, 'Without steps', [], 'ready', 2);
    // Left pending, so the run stays In Progress and open to corrections.
    $this->qaCase($suite, 'Not executed yet', [], 'ready', 3);
    $run = $this->qaRun($plan, $lead, $tester);
    [$stepped, $plain] = $this->executions($run);
    [$open, $save] = array_values($stepped->getStepResults());
    $this->setCurrentUser($tester);

    // First results are not corrections.
    $this->recorder()->record($open, 'passed');
    $this->recorder()->record($save, 'passed');
    $this->recorder()->record($plain, 'passed');
    $this->assertSame([], $this->corrections($run));
    [$stepped] = $this->executions($run);
    $this->assertSame('passed', $stepped->getResult());

    // A changed result needs a reason; nothing is written without one.
    $this->assertRefused(fn () => $this->recorder()->record($plain, 'blocked', ['result_reason' => 'Server down']), 'requires a correction reason');
    $this->assertRefused(fn () => $this->recorder()->record($plain, 'blocked', ['result_reason' => 'Server down'], '   '), 'requires a correction reason');
    $this->assertSame('passed', $this->executions($run)[1]->getResult());
    $this->assertSame([], $this->corrections($run));

    // Execution level: old and new result, reason, author, no step.
    QaTestClock::$now += 60;
    $this->recorder()->record($this->executions($run)[1], 'blocked', ['result_reason' => 'Server down'], 'Wrong environment');
    [$correction] = $this->corrections($run);
    $this->assertSame('execution', $correction->get('level')->value);
    $this->assertSame((string) $plain->id(), (string) $correction->get('execution')->target_id);
    $this->assertTrue($correction->get('step_result')->isEmpty());
    $this->assertSame('passed', $correction->get('old_state')->value);
    $this->assertSame('blocked', $correction->get('new_state')->value);
    $this->assertSame('Wrong environment', $correction->get('reason')->value);
    $this->assertSame((string) $tester->id(), (string) $correction->get('uid')->target_id);
    $this->assertSame((string) $run->id(), (string) $correction->get('run')->target_id);
    $this->assertSame((string) $plan->get('development')->target_id, (string) $correction->get('development')->target_id);
    $this->assertSame('Wrong environment', $this->executions($run)[1]->get('correction_reason')->value);

    // Step level: a step correction that changes the derived execution
    // result leaves a step row and an execution row.
    $this->recorder()->record($this->executions($run)[0]->getStepResults()[$save->id()], 'failed', ['actual_result' => 'Error 500'], 'Misread the screen');
    $corrections = $this->corrections($run);
    $this->assertCount(3, $corrections);
    [, $step_row, $execution_row] = $corrections;
    $this->assertSame('step', $step_row->get('level')->value);
    $this->assertSame((string) $save->id(), (string) $step_row->get('step_result')->target_id);
    $this->assertSame((string) $stepped->id(), (string) $step_row->get('execution')->target_id);
    $this->assertStringContainsString('Save', $step_row->get('step_label')->value);
    $this->assertSame(['passed', 'failed', 'Misread the screen'], [$step_row->get('old_state')->value, $step_row->get('new_state')->value, $step_row->get('reason')->value]);
    $this->assertSame('execution', $execution_row->get('level')->value);
    $this->assertTrue($execution_row->get('step_result')->isEmpty());
    $this->assertSame(['passed', 'failed'], [$execution_row->get('old_state')->value, $execution_row->get('new_state')->value]);
    $this->assertStringContainsString('Misread the screen', $execution_row->get('reason')->value);
    $this->assertSame('failed', $this->executions($run)[0]->getResult());

    // A step correction that leaves the execution result as it was adds
    // only the step row.
    $this->recorder()->record($this->executions($run)[0]->getStepResults()[$open->id()], 'failed', ['actual_result' => 'Did not open'], 'Second look');
    $this->assertCount(4, $this->corrections($run));
    $this->assertSame('step', $this->corrections($run)[3]->get('level')->value);

    // Corrections are never changed or removed: by storage, for anybody.
    $storage = $this->container->get('entity_type.manager')->getStorage('qa_result_correction');
    $row = QaResultCorrection::load($correction->id());
    $row->set('reason', 'Rewritten history');
    try {
      $row->save();
      $this->fail('A correction cannot be updated.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('cannot be modified', $e->getMessage());
    }
    foreach ([fn () => QaResultCorrection::load($correction->id())->delete(), fn () => $storage->delete([QaResultCorrection::load($correction->id())])] as $delete) {
      try {
        $delete();
        $this->fail('A correction cannot be deleted.');
      }
      catch (\LogicException | EntityStorageException $e) {
        $this->assertStringContainsString('immutable audit trail', $e->getMessage());
      }
    }
    foreach ([fn () => QaResultCorrection::load($correction->id())->createDuplicate(), fn () => QaResultCorrection::create(['execution' => $plain->id(), 'old_state' => 'passed', 'new_state' => 'failed', 'reason' => ''])->save()] as $forge) {
      try {
        $forge();
        $this->fail('Corrections cannot be duplicated or written without a reason.');
      }
      catch (\LogicException | EntityStorageException) {
      }
    }
    $storage->resetCache();
    $this->assertSame('Wrong environment', QaResultCorrection::load($correction->id())->get('reason')->value);
    $this->assertCount(4, $this->corrections($run));
    foreach ([$tester, $lead, $manager] as $account) {
      $row = QaResultCorrection::load($correction->id());
      $this->assertTrue($row->access('view', $account), $account->getAccountName());
      $this->assertFalse($row->access('update', $account), $account->getAccountName());
      $this->assertFalse($row->access('delete', $account), $account->getAccountName());
    }
    // They also keep what they refer to from being deleted.
    $this->assertFalse($this->executions($run)[1]->access('delete', $manager));
    $this->assertFalse(QaTestRun::load($run->id())->access('delete', $manager));
  }

  public function testOnlyTheAssignedTesterOrALeadRecordWhileInProgress(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $colleague = $this->qaUser('tester');
    $reader = $this->qaUser('user');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester, $colleague, $reader]));
    $suite = $this->qaSuite($plan);
    $this->qaCase($suite, 'First', [], 'ready', 1);
    $this->qaCase($suite, 'Second', [], 'ready', 2);
    $run = $this->qaRun($plan, $lead, $tester, FALSE);

    // Not while Draft, not even the Tester or a Lead.
    foreach ([$tester, $lead] as $account) {
      $this->setCurrentUser($account);
      $this->assertRefused(fn () => $this->recorder()->record($this->executions($run)[0], 'passed'), 'only be recorded while the Test Run');
    }
    $this->setCurrentUser($tester);
    $this->container->get('divengine_qa.runs')->start($run);

    // A Tester of the same Development who is not assigned, and a QA User,
    // cannot record; the assigned Tester and a Lead can.
    foreach ([$colleague, $reader] as $account) {
      $this->setCurrentUser($account);
      $this->assertRefused(fn () => $this->recorder()->record($this->executions($run)[0], 'passed'), 'Only the Tester assigned to this Run or a QA Lead');
    }
    $this->assertSame('pending', $this->executions($run)[0]->getResult());
    $this->setCurrentUser($tester);
    $this->recorder()->record($this->executions($run)[0], 'passed');
    $this->setCurrentUser($lead);
    $this->recorder()->record($this->executions($run)[1], 'failed', ['actual_result' => 'Broken']);
    [$first, $second] = $this->executions($run);
    $this->assertSame((string) $tester->id(), (string) $first->get('executed_by')->target_id);
    $this->assertSame((string) $lead->id(), (string) $second->get('executed_by')->target_id);

    // The last result completed the run: it is historical, and neither
    // results nor corrections can be recorded any more.
    $run = QaTestRun::load($run->id());
    $this->assertSame('completed', $run->getState());
    foreach ([$tester, $lead] as $account) {
      $this->setCurrentUser($account);
      $this->assertRefused(fn () => $this->recorder()->record($this->executions($run)[0], 'blocked', ['result_reason' => 'Late'], 'Too late'), 'only be recorded while the Test Run');
    }
    $this->assertSame([], $this->corrections($run));
  }

  /**
   * Characterization: the last result of a case after a late correction.
   *
   * Pending maintainer decision (see issue #9). QaResultRecorder::stamp()
   * sets execution_date to the time of the correction, and
   * QaMetrics::refreshCase() takes the execution with the latest
   * execution_date as the case's last result. Correcting the result of an
   * older run after a newer run recorded one therefore makes the older,
   * corrected execution the case's last result. This test records that
   * behaviour; it is not a statement that it is the intended one.
   */
  public function testCorrectingAnOlderRunMakesItTheLastResultOfTheCase(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester]));
    $case = $this->qaCase($this->qaSuite($plan), 'Shared case');
    $older = $this->qaRun($plan, $lead, $tester, TRUE, 'Older');
    $newer = $this->qaRun($plan, $lead, $tester, TRUE, 'Newer');
    $this->setCurrentUser($tester);

    QaTestClock::$now = 1_800_000_100;
    $this->recorder()->record($this->executions($older)[0], 'passed');
    QaTestClock::$now = 1_800_000_200;
    $this->recorder()->record($this->executions($newer)[0], 'failed', ['actual_result' => 'Regression']);
    $this->assertSame('failed', QaTestCase::load($case->id())->get('last_result')->value);
    // Both runs are completed by their only result; the older one is
    // reopened by a Manager to be corrected, as the workflow requires.
    $manager = $this->qaUser('manager');
    $this->setCurrentUser($manager);
    $this->container->get('divengine_qa.runs')->reopen(QaTestRun::load($older->id()), 'Correct an old result');
    $this->setCurrentUser($tester);

    QaTestClock::$now = 1_800_000_300;
    $this->recorder()->record($this->executions($older)[0], 'blocked', ['result_reason' => 'Data missing'], 'It never really ran');
    $corrected = $this->executions($older)[0];
    $this->assertSame(1_800_000_300, (int) $corrected->get('execution_date')->value, 'A correction restamps the execution date.');
    $this->assertSame(1_800_000_200, (int) $this->executions($newer)[0]->get('execution_date')->value);
    // CURRENT behaviour: the corrected older execution, not the newer run's
    // Failed result, is the case's last result.
    $this->assertSame('blocked', QaTestCase::load($case->id())->get('last_result')->value);
    $this->assertSame([(string) $corrected->id()], array_map(fn ($c) => (string) $c->get('execution')->target_id, $this->corrections($older)));
  }

}
