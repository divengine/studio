<?php

declare(strict_types=1);

namespace Drupal\divengine_qa\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_qa\Entity\QaStepResult;
use Drupal\divengine_qa\Entity\QaTestExecution;
use Drupal\divengine_qa\QaPermissions;
use Drupal\file\Entity\File;

/**
 * Attaches evidence to an execution or a step result.
 *
 * Evidence is append-only: it can be added while the run is In Progress
 * (and, on a Completed run, by its Tester or a QA Lead), never removed
 * through the workflow.
 */
class AddEvidenceForm extends QaWizardBase {

  protected QaTestExecution|QaStepResult $record;

  public function getFormId(): string {
    return 'divengine_qa_add_evidence';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $type = '', string $id = ''): array {
    $this->record = $this->entityTypeManager->getStorage($type === 'step' ? 'qa_step_result' : 'qa_test_execution')->load($id);
    $form['#title'] = $this->t('Add evidence to @record', ['@record' => $this->record->label()]);
    $settings = $this->record->getFieldDefinition('evidence')->getSettings();
    $form['files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Files'),
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#upload_location' => $settings['uri_scheme'] . '://qa/' . date('Y-m'),
      '#upload_validators' => ['FileExtension' => ['extensions' => $settings['file_extensions']]],
      '#description' => $this->t('Screenshots, logs or recordings that show what happened.'),
    ];
    $form['actions'] = $this->submitButton((string) $this->t('Attach'));
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $run = $this->record->get('run')->entity;
    $assigned = (int) $run->get('tester')->target_id === (int) $this->user->id();
    $message = match (TRUE) {
      $run->getState() === 'draft' => $this->t('Evidence can only be attached once the Test Run is In Progress.'),
      $run->getState() === 'cancelled' => $this->t('Evidence on a Completed or Cancelled Test Run is historical and cannot be changed.'),
      $run->getState() === 'completed' && !QaPermissions::isLead($this->user) && !($assigned && QaPermissions::isTester($this->user)) => $this->t('Only the Tester assigned to this Run or a QA Lead can add evidence to a Completed Test Run.'),
      !QaPermissions::isLead($this->user) && !($assigned && QaPermissions::isTester($this->user)) => $this->t('Only the Tester assigned to this Run or a QA Lead can add evidence.'),
      default => NULL,
    };
    if ($message) {
      $form_state->setErrorByName('files', $message);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    foreach ((array) $form_state->getValue('files') as $fid) {
      if ($file = File::load($fid)) {
        $file->setPermanent();
        $file->save();
        $this->record->get('evidence')->appendItem(['target_id' => $fid]);
      }
    }
    $this->record->saveTrusted();
    $this->messenger()->addStatus($this->t('Evidence attached.'));
    $execution = $this->record instanceof QaStepResult ? $this->record->get('execution')->entity : $this->record;
    $form_state->setRedirectUrl($execution->toUrl());
  }

}
