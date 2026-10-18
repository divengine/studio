<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestExecution;

/**
 * Turns an ad-hoc scenario into a Draft test case (QA Lead).
 */
class PromoteScenarioForm extends QaWizardBase {

  protected QaTestExecution $execution;

  public function getFormId(): string {
    return 'divengine_qa_promote_scenario';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestExecution $qa_test_execution = NULL): array {
    $this->execution = $qa_test_execution;
    $form['#title'] = $this->t('Promote to Test Case');
    $suites = $this->entityTypeManager->getStorage('qa_test_suite');
    $ids = $suites->getQuery()->accessCheck(TRUE)->condition('plan', $this->execution->get('plan')->target_id)->sort('weight')->execute();
    $options = array_map(fn ($suite) => $suite->label(), $suites->loadMultiple($ids));
    $first_step = current($this->execution->getStepResults());
    if ($existing = $this->design->promotedCases($this->execution)) {
      $form['existing'] = ['#theme' => 'status_messages', '#message_list' => ['warning' => [$this->t('Already promoted to @cases.', ['@cases' => implode(', ', array_map(fn ($c) => $c->label(), $existing))])]], '#status_headings' => ['warning' => $this->t('Warning')]];
      $form['confirm_duplicate'] = ['#type' => 'checkbox', '#title' => $this->t('Create another Test Case anyway')];
    }
    $form['suite'] = ['#type' => 'select', '#title' => $this->t('Target Test Suite'), '#options' => $options, '#required' => TRUE, '#default_value' => count($options) === 1 ? key($options) : NULL];
    $form['name'] = ['#type' => 'textfield', '#title' => $this->t('Test Case Title'), '#required' => TRUE, '#default_value' => $this->execution->get('snapshot_name')->value];
    $defaults = [
      'description' => [$this->t('Description / Objective'), 'snapshot_description'],
      'preconditions' => [$this->t('Preconditions'), 'snapshot_preconditions'],
      'test_data' => [$this->t('Test Data'), 'snapshot_test_data'],
      'acceptance_criteria' => [$this->t('Acceptance Criteria'), 'snapshot_acceptance_criteria'],
    ];
    foreach ($defaults as $key => [$title, $field]) {
      $form[$key] = ['#type' => 'textarea', '#title' => $title, '#rows' => 2, '#default_value' => $this->execution->get($field)->value];
    }
    $form['create_step'] = ['#type' => 'checkbox', '#title' => $this->t('Create a Test Step'), '#default_value' => TRUE];
    $form['step_action'] = ['#type' => 'textfield', '#title' => $this->t('Step Action'), '#default_value' => $first_step ? $first_step->get('action')->value : '', '#states' => ['visible' => [':input[name="create_step"]' => ['checked' => TRUE]]]];
    $form['step_expected_result'] = ['#type' => 'textarea', '#title' => $this->t('Step Expected Result'), '#rows' => 2, '#default_value' => $first_step ? $first_step->get('expected_result')->value : '', '#states' => ['visible' => [':input[name="create_step"]' => ['checked' => TRUE]]]];
    $form['actions'] = $this->submitButton((string) $this->t('Promote'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $suite = $this->entityTypeManager->getStorage('qa_test_suite')->load($form_state->getValue('suite'));
    $case = $this->attempt($form_state, fn () => $this->design->promote($this->execution, $suite, $form_state->getValues(), (bool) $form_state->getValue('create_step'), (bool) $form_state->getValue('confirm_duplicate')));
    if ($case) {
      $form_state->setRedirectUrl($case->toUrl());
    }
  }

}
