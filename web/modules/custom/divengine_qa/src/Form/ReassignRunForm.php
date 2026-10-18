<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaTestRun;

/**
 * Hands a run to another Tester, with a reason, before any work.
 */
class ReassignRunForm extends QaWizardBase {

  protected QaTestRun $run;

  public function getFormId(): string {
    return 'divengine_qa_reassign_run';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?QaTestRun $qa_test_run = NULL): array {
    $this->run = $qa_test_run;
    $form['#title'] = $this->t('Reassign @run', ['@run' => $this->run->label()]);
    $form['intro'] = $this->intro((string) $this->t("A Run can be reassigned only while it is Draft or In Progress and before any result, correction, scenario or evidence has been recorded. The previous and new Tester, your name, the date and the reason are recorded in the Run's activity."));
    $form['current'] = ['#type' => 'item', '#title' => $this->t('Current Tester'), '#plain_text' => $this->run->get('tester')->entity?->getDisplayName() ?? $this->t('Unassigned')];
    $form['tester'] = ['#type' => 'entity_autocomplete', '#target_type' => 'user', '#title' => $this->t('New Tester'), '#required' => TRUE];
    $form['reason'] = ['#type' => 'textarea', '#title' => $this->t('Reason'), '#required' => TRUE, '#placeholder' => $this->t('Why does another Tester take over this Run?')];
    $form['actions'] = $this->submitButton((string) $this->t('Reassign'));
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tester = $this->entityTypeManager->getStorage('user')->load($form_state->getValue('tester'));
    if ($this->attempt($form_state, fn () => $this->runs->reassign($this->run, $tester, (string) $form_state->getValue('reason')) ?? TRUE)) {
      $form_state->setRedirectUrl($this->run->toUrl());
    }
  }

}
