<?php

declare(strict_types=1);

namespace Drupal\divengine_qa;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_qa\Entity\QaTestCase;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\Entity\QaTestPlan;
use Drupal\divengine_qa\Entity\QaTestSuite;

/**
 * Design operations: cloning a plan and promoting an ad-hoc scenario.
 */
class QaDesign {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected QaLogger $logger,
  ) {}

  /**
   * Copies a plan, its suite tree, cases and steps for another environment.
   *
   * The copy is a Draft plan with new plan and suite references; case codes
   * are kept as they are. Runs, results and QA Issues are not copied.
   */
  public function clonePlan(QaTestPlan $plan, string $environment, string $stage, string $name = ''): QaTestPlan {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can clone a Test Plan.'));
    }
    $environment_entity = $this->entityTypeManager->getStorage('qa_environment')->load($environment);
    if (!$environment_entity) {
      throw new QaException((string) $this->t('Select a valid target Environment.'));
    }
    if (!$this->entityTypeManager->getStorage('qa_stage')->load($stage)) {
      throw new QaException((string) $this->t('Select a valid target Stage.'));
    }
    $name = trim($name) ?: $plan->get('name')->value . ' - ' . $environment_entity->label();
    /** @var \Drupal\divengine_qa\Entity\QaTestPlan $copy */
    $copy = $this->entityTypeManager->getStorage('qa_test_plan')->create([
      'development' => $plan->get('development')->target_id,
      'name' => $name,
      'description' => $plan->get('description')->value,
      'responsible' => $plan->get('responsible')->target_id,
      'start_date' => $plan->get('start_date')->value,
      'end_date' => $plan->get('end_date')->value,
      'tags' => $plan->get('tags')->getValue(),
      'environment' => $environment,
      'stage' => $stage,
      'state' => 'draft',
    ]);
    $copy->save();

    $suites = $this->entityTypeManager->getStorage('qa_test_suite');
    $cases = $this->entityTypeManager->getStorage('qa_test_case');
    $steps = $this->entityTypeManager->getStorage('qa_test_step');
    $map = [];
    $originals = $suites->loadMultiple($suites->getQuery()->accessCheck(FALSE)->condition('plan', $plan->id())->sort('weight')->sort('id')->execute());
    foreach ($originals as $suite) {
      $new = $suites->create([
        'plan' => $copy->id(),
        'name' => $suite->get('name')->value,
        'weight' => $suite->get('weight')->value,
        'description' => $suite->get('description')->value,
        'tags' => $suite->get('tags')->getValue(),
        'state' => $suite->get('state')->value,
      ]);
      $new->save();
      $map[$suite->id()] = $new;
    }
    foreach ($originals as $suite) {
      if (($parent = $suite->get('parent')->target_id) && isset($map[$parent])) {
        $map[$suite->id()]->set('parent', $map[$parent]->id())->save();
      }
      foreach ($cases->loadMultiple($cases->getQuery()->accessCheck(FALSE)->condition('suite', $suite->id())->execute()) as $case) {
        $new_case = $cases->create([
          'suite' => $map[$suite->id()]->id(),
          'code' => $case->get('code')->value,
          'name' => $case->get('name')->value,
          'weight' => $case->get('weight')->value,
          'description' => $case->get('description')->value,
          'preconditions' => $case->get('preconditions')->value,
          'test_data' => $case->get('test_data')->value,
          'acceptance_criteria' => $case->get('acceptance_criteria')->value,
          'postconditions' => $case->get('postconditions')->value,
          'priority' => $case->get('priority')->target_id,
          'tags' => $case->get('tags')->getValue(),
          'state' => $case->get('state')->value,
        ]);
        $new_case->save();
        foreach ($steps->loadMultiple($steps->getQuery()->accessCheck(FALSE)->condition('case', $case->id())->execute()) as $step) {
          $steps->create([
            'case' => $new_case->id(),
            'name' => $step->get('name')->value,
            'expected_result' => $step->get('expected_result')->value,
            'description' => $step->get('description')->value,
            'weight' => $step->get('weight')->value,
            'is_skippable' => $step->get('is_skippable')->value,
          ])->save();
        }
      }
    }
    return $copy;
  }

  /**
   * Cases already promoted from a scenario.
   *
   * @return \Drupal\divengine_qa\Entity\QaTestCase[]
   */
  public function promotedCases(QaTestExecution $execution): array {
    return $this->entityTypeManager->getStorage('qa_test_case')->loadByProperties(['source_execution' => $execution->id()]);
  }

  /**
   * Turns an ad-hoc scenario into a Draft test case of the run's plan.
   *
   * @param array{name: string, description?: string, preconditions?: string, test_data?: string, acceptance_criteria?: string, step_action?: string, step_expected_result?: string} $values
   */
  public function promote(QaTestExecution $execution, ?QaTestSuite $suite, array $values, bool $create_step, bool $confirm_duplicate): QaTestCase {
    if (!QaPermissions::isLead($this->currentUser)) {
      throw new QaException((string) $this->t('Only a QA Lead or Manager can promote a scenario.'));
    }
    if (!$execution->isAdHoc()) {
      throw new QaException((string) $this->t('Only ad-hoc scenarios can be promoted; planned executions already have their Test Case.'));
    }
    if (!$suite) {
      throw new QaException((string) $this->t('Select the Test Suite that will hold the new Test Case.'));
    }
    if ((string) $suite->get('plan')->target_id !== (string) $execution->get('plan')->target_id) {
      throw new QaException((string) $this->t('The target Test Suite must belong to the Test Plan of the Run where the scenario was discovered.'));
    }
    if (trim($values['name'] ?? '') === '') {
      throw new QaException((string) $this->t('A Test Case title is required.'));
    }
    if (($existing = $this->promotedCases($execution)) && !$confirm_duplicate) {
      throw new QaException((string) $this->t("This scenario was already promoted to @cases. Open that Test Case, or tick 'Create another Test Case anyway'.", ['@cases' => implode(', ', array_map(fn ($c) => $c->label(), $existing))]));
    }
    /** @var \Drupal\divengine_qa\Entity\QaTestCase $case */
    $case = $this->entityTypeManager->getStorage('qa_test_case')->create([
      'suite' => $suite->id(),
      'name' => trim($values['name']),
      'description' => $values['description'] ?? NULL,
      'preconditions' => $values['preconditions'] ?? NULL,
      'test_data' => $values['test_data'] ?? NULL,
      'acceptance_criteria' => $values['acceptance_criteria'] ?? NULL,
      'state' => 'draft',
      'source_execution' => $execution->id(),
    ]);
    $case->save();
    if ($create_step && trim($values['step_action'] ?? '') !== '') {
      $this->entityTypeManager->getStorage('qa_test_step')->create([
        'case' => $case->id(),
        'name' => trim($values['step_action']),
        'expected_result' => $values['step_expected_result'] ?? NULL,
        'weight' => 10,
      ])->save();
    }
    $run = $execution->get('run')->entity;
    $this->logger->log($case, $this->t('Promoted from ad-hoc scenario @exec of Test Run @run.', ['@exec' => $execution->label(), '@run' => $run?->label()]));
    $this->logger->log($execution, $this->t('Promoted to the draft Test Case @case.', ['@case' => $case->label()]));
    return $case;
  }

}
