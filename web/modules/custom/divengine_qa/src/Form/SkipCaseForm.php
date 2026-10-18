<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestExecution;

/**
 * Records a whole test case as Skipped with one reason.
 */
class SkipCaseForm extends QaWizardBase {

  protected QaTestExecution $execution;

  public function getFormId(): string {
    return 'divengine_qa_skip_case';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestExecution $qa_test_execution = NULL): array {
    $this->execution = $qa_test_execution;
    $form['#title'] = $this->t('Skip Test Case');
    $form['intro'] = $this->intro((string) $this->t('The whole Test Case is recorded as Skipped with this reason. Its steps are not marked Skipped: they stay as they are and do not hold the Run open. Use this when the case is intentionally not executed in this Run; if a prerequisite is missing, mark the steps Blocked instead.'));
    $form['case'] = ['#type' => 'item', '#title' => $this->t('Test Case / Scenario'), '#plain_text' => trim($this->execution->get('snapshot_code')->value . ' ' . $this->execution->get('snapshot_name')->value)];
    $form['steps'] = ['#type' => 'item', '#title' => $this->t('Steps'), '#plain_text' => (string) count($this->execution->getStepResults())];
    $form['reason'] = ['#type' => 'textarea', '#title' => $this->t('Reason'), '#required' => TRUE];
    $form['actions'] = $this->submitButton((string) $this->t('Skip Test Case'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->attempt($form_state, fn () => $this->results->skipCase($this->execution, (string) $form_state->getValue('reason')) ?? TRUE)) {
      $form_state->setRedirectUrl($this->execution->toUrl());
    }
  }

}
