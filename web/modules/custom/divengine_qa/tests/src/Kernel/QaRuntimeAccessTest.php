<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\divengine_qa\Entity\QaIssue;
use Drupal\divengine_qa\QaException;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C09a: run records and their private evidence reach only their people.
 *
 * Runs, executions, step results and corrections are seen by the run's
 * Tester and the QA Leads of the Development; evidence files are private
 * and downloadable exactly by those who see the record holding them, the
 * way core decides private file access (hook_file_download and the file
 * entity's "download" access).
 */
#[RunTestsInSeparateProcesses]
class QaRuntimeAccessTest extends KernelTestBase {

  use QaFixtureTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa'];

  protected function setUp(): void {
    parent::setUp();
    $this->installQaFixture();
    // Anonymous visitors hold QA permissions, so a refusal comes from the
    // access rules, not from a missing permission.
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('use qa')->grantPermission('execute qa tests')->save();
  }

  /**
   * A permanent private evidence file, as the evidence form stores it.
   */
  protected function evidence(string $name, AccountInterface $owner): FileInterface {
    $directory = 'private://qa/2026-10';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents("$directory/$name", "Evidence $name");
    $file = File::create(['uri' => "$directory/$name", 'uid' => $owner->id()]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Forgets the access results computed so far, as a new request would.
   */
  protected function resetAccess(): void {
    $manager = $this->container->get('entity_type.manager');
    foreach (['file', 'development', 'qa_test_plan', 'qa_test_run', 'qa_test_execution', 'qa_step_result', 'qa_result_correction', 'qa_issue'] as $entity_type_id) {
      $manager->getAccessControlHandler($entity_type_id)->resetCache();
      $manager->getStorage($entity_type_id)->resetCache();
    }
  }

  /**
   * Whether core serves the private file to an account.
   *
   * Mirrors FileDownloadController: any -1 from hook_file_download denies,
   * no headers at all is "not found" (denied), otherwise it is served. The
   * file entity's "download" access must agree.
   */
  protected function downloadable(FileInterface $file, AccountInterface $account): bool {
    $this->setCurrentUser($account);
    $this->resetAccess();
    $headers = $this->container->get('module_handler')->invokeAll('file_download', [$file->getFileUri()]);
    $served = !in_array(-1, $headers, TRUE) && $headers !== [];
    $this->assertSame($served, File::load($file->id())->access('download', $account), 'Hook and entity access agree for ' . $account->getAccountName());
    return $served;
  }

  /**
   * The IDs an account finds in an access-checked entity query.
   */
  protected function found(string $entity_type_id, AccountInterface $account): array {
    $this->setCurrentUser($account);
    $ids = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->getQuery()->accessCheck(TRUE)->sort('id')->execute();
    // The same rows through SQL tagged for access, as Views queries are.
    $sql = Database::getConnection()->select($entity_type_id, 'base')->fields('base', ['id'])->addTag($entity_type_id . '_access')->orderBy('id')->execute()->fetchCol();
    $this->assertSame(array_map('strval', array_values($ids)), array_map('strval', $sql), "$entity_type_id for " . $account->getAccountName());
    return array_map('strval', array_values($ids));
  }

  public function testRuntimeRecordsAndEvidenceFollowTheRun(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $colleague = $this->qaUser('tester');
    $reader = $this->qaUser('user');
    $outsider = $this->qaUser('lead');
    $anonymous = new AnonymousUserSession();
    $development = $this->qaDevelopment($lead, [$tester, $colleague, $reader]);
    $plan = $this->qaPlan($development);
    $suite = $this->qaSuite($plan);
    $this->qaCase($suite, 'Pay', ['Pay by card' => 'Paid']);
    $this->qaCase($suite, 'Refund');
    $run = $this->qaRun($plan, $lead, $tester);
    [$execution, $other] = $this->executions($run);
    $step = current($execution->getStepResults());

    // The Tester records a failure with evidence, then corrects it.
    $this->setCurrentUser($tester);
    $results = $this->container->get('divengine_qa.results');
    $results->record($step, 'failed', ['actual_result' => 'Declined']);
    $execution_file = $this->evidence('execution.png', $tester);
    $step_file = $this->evidence('step.log', $tester);
    $execution = $this->executions($run)[0];
    $execution->get('evidence')->appendItem($execution_file->id());
    $execution->saveTrusted();
    $step = current($execution->getStepResults());
    $step->get('evidence')->appendItem($step_file->id());
    $step->saveTrusted();
    $results->record(current($this->executions($run)[0]->getStepResults()), 'blocked', ['result_reason' => 'Gateway down'], 'Not a product defect');
    $corrections = $this->corrections($run);
    $this->assertCount(2, $corrections);

    $records = [$run, $this->executions($run)[0], current($this->executions($run)[0]->getStepResults()), $corrections[0]];
    $allowed = ['tester' => $tester, 'lead' => $lead];
    $refused = ['colleague' => $colleague, 'reader' => $reader, 'outsider' => $outsider, 'anonymous' => $anonymous];

    foreach ($allowed as $who => $account) {
      foreach ($records as $record) {
        $this->assertTrue($record->access('view', $account), "$who views " . $record->getEntityTypeId());
      }
      $this->assertTrue($this->downloadable($execution_file, $account), "$who downloads execution evidence");
      $this->assertTrue($this->downloadable($step_file, $account), "$who downloads step evidence");
      $this->assertSame([(string) $run->id()], $this->found('qa_test_run', $account), $who);
      $this->assertCount(2, $this->found('qa_test_execution', $account), $who);
      $this->assertCount(1, $this->found('qa_step_result', $account), $who);
      $this->assertCount(2, $this->found('qa_result_correction', $account), $who);
    }
    foreach ($refused as $who => $account) {
      foreach ($records as $record) {
        $this->assertFalse($record->access('view', $account), "$who views " . $record->getEntityTypeId());
      }
      $this->assertFalse($this->downloadable($execution_file, $account), "$who downloads execution evidence");
      $this->assertFalse($this->downloadable($step_file, $account), "$who downloads step evidence");
      foreach (['qa_test_run', 'qa_test_execution', 'qa_step_result', 'qa_result_correction'] as $entity_type_id) {
        $this->assertSame([], $this->found($entity_type_id, $account), "$who finds $entity_type_id");
      }
    }
    // The colleague and the reader still see the plan: the restriction is
    // the run's, not the Development's.
    $this->assertTrue($plan->access('view', $colleague));
    $this->assertTrue($plan->access('view', $reader));
    $this->assertFalse($plan->access('view', $outsider));

    // Reassigning a run with recorded work is refused, so evidence cannot
    // be handed over to another Tester that way.
    $this->setCurrentUser($lead);
    try {
      $this->container->get('divengine_qa.runs')->reassign($run, $colleague, 'Hand over');
      $this->fail('A run with recorded work keeps its Tester.');
    }
    catch (QaException $e) {
      $this->assertStringContainsString('recorded work', $e->getMessage());
    }

    // Leaving the Development removes access to the run and its evidence,
    // even for the assigned Tester.
    $development->set('members', [$colleague->id(), $reader->id()])->save();
    $this->resetAccess();
    $this->assertFalse($this->executions($run)[0]->access('view', $tester));
    $this->assertFalse($this->downloadable($execution_file, $tester));
    $this->assertFalse($this->downloadable($step_file, $tester));
    $this->assertSame([], $this->found('qa_test_execution', $tester));
    $this->assertTrue($this->downloadable($execution_file, $lead));
    $this->assertFalse($other->access('view', $colleague));
  }

  /**
   * Evidence copied into a QA Issue.
   *
   * Invariant: users outside the Development never download the copy.
   *
   * Characterization, pending maintainer decision (see issue #9): QA Issues
   * are design-level records (QaAccessControlHandler::designAccess()) read
   * by every QA user of the Development, so the copy QaIssues::copyEvidence()
   * makes under private://qa/issues/<id> is downloadable by any QA user of
   * the Development who can view the issue, including Testers who could not
   * download the original evidence of the run. This test records that
   * behaviour; it is not a statement that it is the intended one.
   */
  public function testEvidenceCopiedToAnIssue(): void {
    $lead = $this->qaUser('lead');
    // Reporting an issue writes its description in the QA Issue format.
    $tester = $this->qaUser('tester', ['use text format qa_issue']);
    $colleague = $this->qaUser('tester');
    $reader = $this->qaUser('user');
    $outsider = $this->qaUser('lead');
    $development = $this->qaDevelopment($lead, [$tester, $colleague, $reader]);
    $plan = $this->qaPlan($development);
    $this->qaCase($this->qaSuite($plan), 'Pay');
    $this->qaCase($this->qaSuite($plan, 'Other', 1), 'Refund');
    $run = $this->qaRun($plan, $lead, $tester);
    $this->setCurrentUser($tester);
    $this->container->get('divengine_qa.results')->record($this->executions($run)[0], 'failed', ['actual_result' => 'Declined']);
    $original = $this->evidence('failure.png', $tester);
    $execution = $this->executions($run)[0];
    $execution->get('evidence')->appendItem($original->id());
    $execution->saveTrusted();

    $issue = $this->container->get('divengine_qa.issues')->create($execution, NULL, 'Card declined', '<p>Declined.</p>', '2', TRUE);
    $issue = QaIssue::load($issue->id());
    $this->assertCount(1, $issue->get('evidence'));
    $copy = $issue->get('evidence')->entity;
    $this->assertNotSame((string) $original->id(), (string) $copy->id());
    $this->assertStringStartsWith('private://qa/issues/' . $issue->id() . '/', $copy->getFileUri());

    // Invariant: nobody outside the Development reaches the copy.
    foreach (['outsider' => $outsider, 'anonymous' => new AnonymousUserSession()] as $who => $account) {
      $this->assertFalse($issue->access('view', $account), $who);
      $this->assertFalse($this->downloadable($copy, $account), $who);
    }
    $this->assertTrue($this->downloadable($copy, $tester));
    $this->assertTrue($this->downloadable($copy, $lead));

    // CURRENT behaviour: QA users of the Development who are not on the run
    // download the copy, while the original stays restricted to the run.
    foreach (['colleague' => $colleague, 'reader' => $reader] as $who => $account) {
      $this->assertTrue($issue->access('view', $account), $who);
      $this->assertTrue($this->downloadable($copy, $account), "$who downloads the issue copy");
      $this->assertFalse($this->downloadable($original, $account), "$who cannot download the run original");
    }

    // Leaving the Development removes access to the copy too.
    $development->set('members', [$tester->id()])->save();
    $this->assertFalse($this->downloadable($copy, $colleague));
  }

}
