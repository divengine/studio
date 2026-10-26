<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Functional;

use Drupal\Core\File\FileSystemInterface;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\Entity\QaTestStep;
use Drupal\divengine_qa\Entity\QaTestSuite;
use Drupal\file\Entity\File;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Private QA evidence is served over HTTP only to the run's people. */
#[RunTestsInSeparateProcesses]
class QaEvidenceAccessHttpTest extends BrowserTestBase {

  protected static $modules = ['divengine_qa', 'page_cache', 'dynamic_page_cache'];

  protected $defaultTheme = 'stark';

  public function testPrivateEvidenceAndMembershipChanges(): void {
    $tester_permissions = ['use unified process', 'use qa', 'execute qa tests'];
    $lead = $this->drupalCreateUser(array_merge($tester_permissions, ['manage qa']));
    $tester = $this->drupalCreateUser(array_merge($tester_permissions, ['use text format qa_issue']));
    $colleague = $this->drupalCreateUser($tester_permissions);
    $outsider = $this->drupalCreateUser(array_merge($tester_permissions, ['manage qa']));
    $this->grantPermissions(Role::load('anonymous'), ['use qa', 'execute qa tests']);

    $this->setCurrentUser($lead);
    $development = Development::create(['name' => 'Private QA', 'uid' => $lead->id(), 'members' => [$tester->id(), $colleague->id()]]); $development->save();
    $plan = QaTestPlan::create(['name' => 'Plan', 'development' => $development->id(), 'environment' => 'testing', 'stage' => 'system', 'state' => 'active']); $plan->save();
    $suite = QaTestSuite::create(['name' => 'Suite', 'plan' => $plan->id()]); $suite->save();
    $case = QaTestCase::create(['suite' => $suite->id(), 'name' => 'Pay', 'state' => 'ready']); $case->save();
    QaTestStep::create(['case' => $case->id(), 'name' => 'Pay by card', 'expected_result' => 'Paid'])->save();
    $run = QaTestRun::create(['plan' => $plan->id(), 'name' => 'Run', 'tester' => $tester->id()]); $run->save();
    \Drupal::service('divengine_qa.runs')->start($run);
    $execution = current(\Drupal::service('divengine_qa.runs')->executions($run));
    $step = current($execution->getStepResults());

    // Evidence as the evidence form stores it: permanent private files on
    // the execution and on the step.
    $directory = 'private://qa/2026-10';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $files = [];
    foreach (['execution' => $execution, 'step' => $step] as $name => $holder) {
      file_put_contents("$directory/$name.txt", "Private $name evidence");
      $file = File::create(['uri' => "$directory/$name.txt", 'uid' => $tester->id()]);
      $file->setPermanent();
      $file->save();
      $holder->get('evidence')->appendItem($file->id());
      $holder->saveTrusted();
      $files[$name] = $file->createFileUrl();
    }
    $this->assertStringStartsWith('/', $files['execution']);
    $this->assertStringContainsString('/system/files/qa/', $files['execution']);

    foreach ([$tester, $lead] as $account) {
      $this->drupalLogin($account);
      foreach ($files as $name => $url) {
        $this->drupalGet($url);
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->responseContains("Private $name evidence");
      }
    }
    foreach ([$colleague, $outsider] as $account) {
      $this->drupalLogin($account);
      foreach ($files as $name => $url) {
        $this->drupalGet($url);
        $this->assertSession()->statusCodeEquals(403);
        $this->assertSession()->responseNotContains("Private $name evidence");
      }
    }
    $this->drupalLogout();
    foreach ($files as $name => $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->responseNotContains("Private $name evidence");
    }

    // Reporting an issue: copying the evidence is opt-in, and the form warns
    // that copies are shared with the QA users of the Development.
    $this->setCurrentUser($tester);
    \Drupal::service('divengine_qa.results')->record($step, 'failed', ['actual_result' => 'Declined']);
    $this->drupalLogin($tester);
    $this->drupalGet('/qa/execution/' . $execution->id() . '/issue');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxNotChecked('copy_attachments');
    $this->assertSession()->pageTextContains('Copies on the QA Issue can be opened by every QA user of the Development [DEV-0001] Private QA, including people who cannot open this Test Run.');
    $issue = \Drupal::service('divengine_qa.issues')->create(\Drupal::entityTypeManager()->getStorage('qa_test_execution')->loadUnchanged($execution->id()), NULL, 'Card declined', '<p>Declined.</p>', '2', TRUE);
    $copies = array_map(fn ($item) => $item->entity->createFileUrl(), iterator_to_array(\Drupal::entityTypeManager()->getStorage('qa_issue')->loadUnchanged($issue->id())->get('evidence')));
    $this->assertCount(2, $copies);
    $this->drupalLogin($colleague);
    foreach ($copies as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);
    }
    $this->drupalLogin($outsider);
    foreach ($copies as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
    }

    // Warm caches as the Tester, then revoke their membership. The next
    // request is separate from the writer and must not reuse the earlier
    // privileged answer.
    $this->drupalLogin($tester);
    $this->drupalGet($files['execution']);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('/qa/execution/' . $execution->id());
    $this->assertSession()->statusCodeEquals(200);
    $development->set('members', [$colleague->id()])->save();
    foreach ($files as $name => $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->responseNotContains("Private $name evidence");
    }
    $this->drupalGet('/qa/execution/' . $execution->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($lead);
    $this->drupalGet($files['step']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Private step evidence');

    // The issue copies follow the Development too: warm them for the
    // colleague, then remove them from it.
    $this->drupalLogin($colleague);
    foreach ($copies as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(200);
    }
    $development->set('members', [])->save();
    foreach ($copies as $url) {
      $this->drupalGet($url);
      $this->assertSession()->statusCodeEquals(403);
      $this->assertSession()->responseNotContains('evidence');
    }
  }

}
