<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Kernel;

use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\Entity\QaTestStep;
use Drupal\divengine_qa\QaException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * C09a: a run executes the plan as it was when its snapshot was taken.
 */
#[RunTestsInSeparateProcesses]
class QaSnapshotTest extends KernelTestBase {

  use QaFixtureTrait;
  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'file', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa'];

  protected function setUp(): void {
    parent::setUp();
    $this->installQaFixture();
  }

  /**
   * The executions and step results of a run, as comparable values.
   */
  protected function snapshot(QaTestRun $run): array {
    $values = [];
    foreach ($this->executions($run) as $execution) {
      $row = [];
      foreach (QaTestExecution::snapshotFields() as $field) {
        $row[$field] = $execution->get($field)->getValue();
      }
      foreach ($execution->getStepResults() as $step) {
        $row['steps'][] = array_combine(QaStepResult::snapshotFields(), array_map(fn ($field) => $step->get($field)->getValue(), QaStepResult::snapshotFields()));
      }
      $values[] = $row;
    }
    return $values;
  }

  public function testRunKeepsTheSnapshotTakenAtCreation(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester]));
    $suite = $this->qaSuite($plan);
    $login = $this->qaCase($suite, 'Log in', ['Open the login page' => 'The form shows', 'Submit valid credentials' => 'The dashboard shows'], 'ready', 1);
    $logout = $this->qaCase($suite, 'Log out', [], 'ready', 2);
    $this->qaCase($suite, 'Draft case', ['Not ready' => 'Never copied'], 'draft', 3);
    $run = $this->qaRun($plan, $lead, $tester, FALSE);

    // Only Ready cases are copied, with their texts and steps.
    $executions = $this->executions($run);
    $this->assertCount(2, $executions);
    $this->assertSame([(string) $login->id(), (string) $logout->id()], array_map(fn ($e) => (string) $e->get('test_case')->target_id, $executions));
    $this->assertSame('Log in', $executions[0]->get('snapshot_name')->value);
    $this->assertSame($login->get('code')->value, $executions[0]->get('snapshot_code')->value);
    $this->assertSame('Log in objective', $executions[0]->get('snapshot_description')->value);
    $this->assertSame('Log in criteria', $executions[0]->get('snapshot_acceptance_criteria')->value);
    $suite_label = $suite->label();
    $this->assertSame($suite_label, $executions[0]->get('suite_name')->value);
    $steps = $executions[0]->getStepResults();
    $this->assertSame(['Open the login page', 'Submit valid credentials'], array_values(array_map(fn ($s) => $s->get('action')->value, $steps)));
    $this->assertSame(['The form shows', 'The dashboard shows'], array_values(array_map(fn ($s) => $s->get('expected_result')->value, $steps)));
    $this->assertSame([], $executions[1]->getStepResults());
    $this->assertSame(2, (int) QaTestRun::load($run->id())->get('execution_count')->value);
    $frozen = $this->snapshot($run);

    // Editing the design afterwards: texts, steps changed, added and
    // removed, a new Ready case, a case no longer Ready, the plan itself.
    $login->set('name', 'Sign in')->set('description', 'Rewritten objective')->set('acceptance_criteria', 'Rewritten criteria')->save();
    $step_ids = \Drupal::entityQuery('qa_test_step')->accessCheck(FALSE)->condition('case', $login->id())->sort('weight')->execute();
    [$first, $second] = array_values(QaTestStep::loadMultiple($step_ids));
    $first->set('name', 'Open the sign-in page')->set('expected_result', 'Changed expectation')->set('is_skippable', TRUE)->save();
    $second->delete();
    QaTestStep::create(['case' => $logout->id(), 'name' => 'A step added later', 'expected_result' => 'Not in the run'])->save();
    $this->qaCase($suite, 'Added later', ['New step' => 'Not in the run'], 'ready', 0);
    $logout->setStateThroughTransition('obsolete')->save();
    $suite->set('name', 'Renamed suite')->save();
    $plan->set('name', 'Renamed plan')->set('environment', 'staging')->save();

    $this->assertSame($frozen, $this->snapshot($run), 'Design changes never reach an existing run.');
    $this->assertSame('Log in', $this->executions($run)[0]->get('snapshot_name')->value);
    $this->assertSame($suite_label, $this->executions($run)[0]->get('suite_name')->value);
    $this->assertCount(2, $this->executions($run)[0]->getStepResults());

    // Starting the run, and asking again for its executions, change nothing.
    $this->assertSame(0, $this->container->get('divengine_qa.snapshot')->build($run));
    $this->container->get('divengine_qa.runs')->start($run);
    $this->assertSame(0, $this->container->get('divengine_qa.runs')->generateExecutions(QaTestRun::load($run->id())));
    $this->assertSame($frozen, $this->snapshot($run));

    // A new run takes its own snapshot of the plan as it is now.
    $later = $this->qaRun($plan, $lead, $tester, FALSE, 'Later run');
    $names = array_map(fn ($e) => $e->get('snapshot_name')->value, $this->executions($later));
    $this->assertSame(['Added later', 'Sign in'], $names);
    $this->assertSame(['Open the sign-in page'], array_values(array_map(fn ($s) => $s->get('action')->value, $this->executions($later)[1]->getStepResults())));
    $this->assertSame($frozen, $this->snapshot($run));
  }

  public function testFrozenFieldsAreRefusedOutsideTheWorkflow(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester]));
    $suite = $this->qaSuite($plan);
    $case = $this->qaCase($suite, 'Pay', ['Pay by card' => 'Paid']);
    $other = $this->qaCase($suite, 'Refund');
    $run = $this->qaRun($plan, $lead, $tester);
    $this->setCurrentUser($lead);
    [$execution] = array_values(array_filter($this->executions($run), fn ($e) => (string) $e->get('test_case')->target_id === (string) $case->id()));
    $step = current($execution->getStepResults());

    // Each frozen field of an execution, changed through an ordinary save
    // path (form, REST, JSON:API all validate), is a violation.
    $changes = [
      'snapshot_name' => 'Another name',
      'snapshot_code' => 'TC-X',
      'snapshot_description' => 'Another objective',
      'snapshot_preconditions' => 'Other preconditions',
      'snapshot_test_data' => 'Other data',
      'snapshot_acceptance_criteria' => 'Other criteria',
      'snapshot_postconditions' => 'Other postconditions',
      'suite_name' => 'Another suite',
      'weight' => 99,
      'test_case' => $other->id(),
      'origin' => 'ad_hoc',
    ];
    foreach ($changes as $field => $value) {
      $edited = QaTestExecution::load($execution->id());
      $edited->set($field, $value);
      $messages = array_map(fn ($v) => (string) $v->getMessage(), iterator_to_array($edited->validate()));
      $this->assertCount(1, $messages, $field);
      $this->assertStringContainsString('Historical snapshot fields are frozen', $messages[0], $field);
      $this->assertStringContainsString((string) $edited->getFieldDefinition($field)->getLabel(), $messages[0], $field);
    }
    foreach (['action' => 'Pay in cash', 'expected_result' => 'Refused', 'snapshot_skippable' => TRUE, 'test_step' => NULL] as $field => $value) {
      $edited = QaStepResult::load($step->id());
      $edited->set($field, $value);
      $messages = array_map(fn ($v) => (string) $v->getMessage(), iterator_to_array($edited->validate()));
      $this->assertCount(1, $messages, $field);
      $this->assertStringContainsString('Historical snapshot fields are frozen', $messages[0], $field);
    }
    // Results and their stamps are not written directly either, and
    // executions are never created by hand.
    $edited = QaTestExecution::load($execution->id())->set('state', 'passed');
    $this->assertStringContainsString('Record results', (string) $edited->validate()[0]->getMessage());
    $edited = QaStepResult::load($step->id())->set('execution_date', 1);
    $this->assertStringContainsString('maintained by the result workflow', (string) $edited->validate()[0]->getMessage());
    $forged = QaTestExecution::create(['run' => $run->id(), 'test_case' => $other->id(), 'snapshot_name' => 'Forged']);
    $this->assertStringContainsString('cannot be created manually', (string) $forged->validate()[0]->getMessage());
    // An unchanged record validates.
    $this->assertCount(0, QaTestExecution::load($execution->id())->validate());

    // Once the run is historical, nothing of it changes at all.
    $this->container->get('divengine_qa.runs')->cancel(QaTestRun::load($run->id()));
    $edited = QaTestExecution::load($execution->id())->set('comments', 'Late remark');
    $this->assertStringContainsString('historical evidence', (string) $edited->validate()[0]->getMessage());
  }

  /**
   * A Draft run prepared before its plan has Ready cases (#9).
   *
   * A Lead may save such a run (the run form); the services that create runs
   * for Testers refuse an empty plan. It cannot start. Its snapshot is taken
   * when a Lead generates its executions, so it holds the plan as it is
   * then, and the run records that moment as its snapshot date. From then on
   * it is frozen like any other snapshot.
   */
  public function testRunCreatedWithoutReadyCasesTakesItsSnapshotOnGeneration(): void {
    $lead = $this->qaUser('lead');
    $tester = $this->qaUser('tester');
    $plan = $this->qaPlan($this->qaDevelopment($lead, [$tester]));
    $suite = $this->qaSuite($plan);
    $case = $this->qaCase($suite, 'Search', ['Type a term' => 'Results show'], 'draft');
    $this->setCurrentUser($lead);
    try {
      $this->container->get('divengine_qa.runs')->createRunsForTesters($plan, [$tester], 'Assigned');
      $this->fail('An empty plan cannot be assigned.');
    }
    catch (QaException $e) {
      $this->assertStringContainsString('no Ready Test Cases', $e->getMessage());
    }

    QaTestClock::$now = 1_800_000_100;
    $run = $this->qaRun($plan, $lead, $tester, FALSE, 'Created empty');
    $this->assertSame([], $this->executions($run));
    $this->assertTrue(QaTestRun::load($run->id())->get('snapshot_date')->isEmpty(), 'No snapshot is recorded while there are no executions.');
    $this->assertSame(0, $this->container->get('divengine_qa.runs')->generateExecutions(QaTestRun::load($run->id())));
    $this->assertTrue(QaTestRun::load($run->id())->get('snapshot_date')->isEmpty(), 'Generating from a plan without Ready cases records no snapshot.');
    try {
      $this->container->get('divengine_qa.runs')->start($run);
      $this->fail('A run without executions cannot start.');
    }
    catch (QaException $e) {
      $this->assertStringContainsString('has no executions', $e->getMessage());
    }

    // The case changes and becomes Ready after the run was created; the
    // run then takes the case as it is at generation time.
    $case->set('name', 'Search renamed after the run was created')->save();
    $case->setStateThroughTransition('ready')->save();
    QaTestStep::create(['case' => $case->id(), 'name' => 'Step added after the run was created', 'expected_result' => 'Copied too', 'weight' => 50])->save();
    $this->setCurrentUser($tester);
    try {
      $this->container->get('divengine_qa.runs')->generateExecutions($run);
      $this->fail('Only a Lead generates executions.');
    }
    catch (QaException $e) {
      $this->assertStringContainsString('Only a QA Lead', $e->getMessage());
    }
    $this->setCurrentUser($lead);
    QaTestClock::$now = 1_800_000_500;
    $this->assertSame(1, $this->container->get('divengine_qa.runs')->generateExecutions(QaTestRun::load($run->id())));
    $this->assertSame(1_800_000_500, (int) QaTestRun::load($run->id())->get('snapshot_date')->value, 'The snapshot date is when the executions were generated.');
    [$execution] = $this->executions($run);
    $this->assertSame('Search renamed after the run was created', $execution->get('snapshot_name')->value);
    $this->assertSame(['Type a term', 'Step added after the run was created'], array_values(array_map(fn ($s) => $s->get('action')->value, $execution->getStepResults())));

    // From then on it is frozen like any other snapshot.
    $frozen = $this->snapshot($run);
    $case->set('name', 'Renamed again')->save();
    $this->qaCase($suite, 'Ready later still');
    QaTestClock::$now = 1_800_000_900;
    $this->assertSame(0, $this->container->get('divengine_qa.runs')->generateExecutions(QaTestRun::load($run->id())));
    $this->assertSame($frozen, $this->snapshot($run));
    $this->assertSame(1_800_000_500, (int) QaTestRun::load($run->id())->get('snapshot_date')->value);

    // Only the workflow sets the snapshot date.
    $edited = QaTestRun::load($run->id())->set('snapshot_date', 1_800_000_000);
    $this->assertStringContainsString('set by the system only', (string) $edited->validate()[0]->getMessage());
  }

}
