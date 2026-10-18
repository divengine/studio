<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestPlan;

/**
 * Creates one Draft run per Tester from a plan.
 */
class AssignRunsForm extends QaWizardBase {

  protected QaTestPlan $plan;

  public function getFormId(): string {
    return 'divengine_qa_assign_runs';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestPlan $qa_test_plan = NULL): array {
    $this->plan = $qa_test_plan;
    $form['#title'] = $this->t('Create Runs for Testers');
    $form['intro'] = $this->intro((string) $this->t("One Draft Run is created per Tester with a snapshot of the Plan's Ready Test Cases. Each Tester starts and executes their own Run."));
    $form['ready'] = ['#type' => 'item', '#title' => $this->t('Ready Test Cases'), '#plain_text' => (string) $this->metrics->plan($this->plan)['ready_cases']];
    $form['testers'] = ['#type' => 'entity_autocomplete', '#target_type' => 'user', '#tags' => TRUE, '#title' => $this->t('Testers'), '#required' => TRUE];
    $form['name_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Run Name'),
      '#required' => TRUE,
      '#default_value' => $this->plan->get('name')->value,
      '#placeholder' => $this->t('e.g. Executor Training 2026-10'),
    ];
    $form['actions'] = $this->submitButton((string) $this->t('Create Runs'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = array_column($form_state->getValue('testers') ?: [], 'target_id');
    $testers = $this->entityTypeManager->getStorage('user')->loadMultiple($ids);
    $runs = $this->attempt($form_state, fn () => $this->runs->createRunsForTesters($this->plan, $testers, (string) $form_state->getValue('name_prefix')));
    if ($runs !== NULL) {
      $this->messenger()->addStatus($this->formatPlural(count($runs), 'Created 1 Test Run.', 'Created @count Test Runs.'));
      $form_state->setRedirectUrl($this->plan->toUrl());
    }
  }

}
