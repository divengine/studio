<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_qa\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestRun;
use Drupal\divengine_qa\Entity\QaTestStep;
use Drupal\divengine_qa\Entity\QaTestSuite;
use Drupal\user\UserInterface;

/**
 * A Development with a QA plan, its people and a controllable clock.
 *
 * The plan's runs, executions and results are real entities written through
 * the QA services, as a request would write them.
 */
trait QaFixtureTrait {

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Results are stamped with the request time; tests move it on.
    $container->getDefinition('datetime.time')->setClass(QaTestClock::class);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  protected function installQaFixture(): void {
    $this->setSetting('file_private_path', $this->container->getParameter('site.path') . '/private');
    foreach (['user', 'file', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline', 'qa_test_plan', 'qa_test_suite', 'qa_test_case', 'qa_test_step', 'qa_test_run', 'qa_test_execution', 'qa_step_result', 'qa_result_correction', 'qa_issue', 'qa_log'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['system', 'filter', 'divengine_artifact', 'divengine_traceability', 'divengine_process', 'divengine_qa']);
    QaTestClock::$now = 1_800_000_000;
    // Consume uid 1; every account of the fixture is an ordinary user.
    $this->createUser();
  }

  /**
   * A user of the Unified Process with a QA role.
   */
  protected function qaUser(string $role, array $extra = []): UserInterface {
    $permissions = match ($role) {
      'user' => ['use qa'],
      'tester' => ['use qa', 'execute qa tests'],
      'lead' => ['use qa', 'execute qa tests', 'manage qa'],
      'manager' => ['use qa', 'execute qa tests', 'manage qa', 'administer qa'],
    };
    return $this->createUser(array_merge(['use unified process'], $permissions, $extra));
  }

  /**
   * A Development created by a Lead, with these other members.
   *
   * @param \Drupal\user\UserInterface[] $members
   */
  protected function qaDevelopment(AccountInterface $lead, array $members): Development {
    $this->setCurrentUser($lead);
    $development = Development::create(['name' => 'QA integrity', 'uid' => $lead->id(), 'members' => array_map(fn (UserInterface $user) => $user->id(), $members)]);
    $development->save();
    return $development;
  }

  protected function qaPlan(Development $development): QaTestPlan {
    $plan = QaTestPlan::create(['name' => 'Release plan', 'development' => $development->id(), 'environment' => 'testing', 'stage' => 'system', 'state' => 'active']);
    $plan->save();
    return $plan;
  }

  protected function qaSuite(QaTestPlan $plan, string $name = 'Checkout', int $weight = 0): QaTestSuite {
    $suite = QaTestSuite::create(['name' => $name, 'plan' => $plan->id(), 'weight' => $weight]);
    $suite->save();
    return $suite;
  }

  /**
   * A test case with its steps, given as [action => expected result].
   *
   * @param string[] $steps
   */
  protected function qaCase(QaTestSuite $suite, string $name, array $steps = [], string $state = 'ready', int $weight = 0): QaTestCase {
    $case = QaTestCase::create([
      'suite' => $suite->id(),
      'name' => $name,
      'weight' => $weight,
      'description' => "$name objective",
      'preconditions' => "$name preconditions",
      'test_data' => "$name data",
      'acceptance_criteria' => "$name criteria",
      'postconditions' => "$name postconditions",
      'state' => $state,
    ]);
    $case->save();
    $weight = 0;
    foreach ($steps as $action => $expected) {
      QaTestStep::create(['case' => $case->id(), 'name' => $action, 'expected_result' => $expected, 'weight' => $weight += 10])->save();
    }
    return $case;
  }

  /**
   * A run assigned to a Tester, created by a Lead and optionally started.
   */
  protected function qaRun(QaTestPlan $plan, AccountInterface $lead, ?AccountInterface $tester, bool $start = TRUE, string $name = 'Run'): QaTestRun {
    $this->setCurrentUser($lead);
    $run = QaTestRun::create(['plan' => $plan->id(), 'name' => $name, 'tester' => $tester?->id()]);
    $run->save();
    if ($start) {
      $this->container->get('divengine_qa.runs')->start($run);
    }
    return QaTestRun::load($run->id());
  }

  /**
   * The executions of a run, in execution order, as stored.
   *
   * @return \Drupal\divengine_qa\Entity\QaTestExecution[]
   */
  protected function executions(QaTestRun $run): array {
    $this->container->get('entity_type.manager')->getStorage('qa_test_execution')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('qa_step_result')->resetCache();
    return array_values($this->container->get('divengine_qa.runs')->executions($run));
  }

  /**
   * The stored correction rows of a run, oldest first.
   *
   * @return \Drupal\divengine_qa\Entity\QaResultCorrection[]
   */
  protected function corrections(QaTestRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('qa_result_correction');
    $storage->resetCache();
    return array_values($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('run', $run->id())->sort('id')->execute()));
  }

}
