<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * Reopens a Completed run (QA Manager) with a reason.
 */
class ReopenRunForm extends QaWizardBase {

  protected QaTestRun $run;

  public function getFormId(): string {
    return 'divengine_qa_reopen_run';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestRun $qa_test_run = NULL): array {
    $this->run = $qa_test_run;
    $form['#title'] = $this->t('Reopen @run', ['@run' => $this->run->label()]);
    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Explain why this historical Run must be corrected...'),
    ];
    $form['actions'] = $this->submitButton((string) $this->t('Reopen'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->attempt($form_state, fn () => $this->runs->reopen($this->run, (string) $form_state->getValue('reason')) ?? TRUE)) {
      $form_state->setRedirectUrl($this->run->toUrl());
    }
  }

}
