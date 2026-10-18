<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * Adds an ad-hoc scenario discovered during an In Progress run.
 */
class AddScenarioForm extends QaWizardBase {

  protected QaTestRun $run;

  public function getFormId(): string {
    return 'divengine_qa_add_scenario';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestRun $qa_test_run = NULL): array {
    $this->run = $qa_test_run;
    $form['#title'] = $this->t('Add Scenario to @run', ['@run' => $this->run->label()]);
    $form['intro'] = $this->intro((string) $this->t('Discovered something the plan does not cover? Add it to this Run and execute it here.'));
    $form['name'] = ['#type' => 'textfield', '#title' => $this->t('Scenario Title'), '#required' => TRUE];
    $form['description'] = ['#type' => 'textarea', '#title' => $this->t('Description / Objective'), '#required' => TRUE, '#rows' => 3];
    $form['expected_result'] = ['#type' => 'textarea', '#title' => $this->t('Expected Result'), '#required' => TRUE, '#rows' => 3];
    $form['preconditions'] = ['#type' => 'textarea', '#title' => $this->t('Preconditions'), '#rows' => 2];
    $form['test_data'] = ['#type' => 'textarea', '#title' => $this->t('Test Data'), '#rows' => 2];
    $form['actions'] = $this->submitButton((string) $this->t('Add and Execute'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $execution = $this->attempt($form_state, fn () => $this->runs->addScenario($this->run, (string) $v['name'], (string) $v['description'], (string) $v['expected_result'], (string) $v['preconditions'], (string) $v['test_data']));
    if ($execution) {
      $form_state->setRedirectUrl($execution->toUrl());
    }
  }

}
