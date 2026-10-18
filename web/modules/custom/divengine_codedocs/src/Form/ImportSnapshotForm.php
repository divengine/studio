<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_codedocs\Contract\ContractException;
use Drupal\divengine_codedocs\Entity\CodeDocSet;

/** File import for people; large extractors use the chunked HTTP API. */
class ImportSnapshotForm extends FormBase {

  public function getFormId(): string { return 'divengine_codedocs_import'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?CodeDocSet $codedoc_set = NULL): array {
    $form_state->set('set', $codedoc_set->id());
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['file'] = ['#type' => 'file', '#title' => $this->t('Documentation JSON'), '#description' => $this->t('Divengine contract, CodeIntel Javadoc or TypeScript extractor output. The project must match this documentation set.')];
    $form['force'] = ['#type' => 'checkbox', '#title' => $this->t('Keep a snapshot even when the documentation is unchanged')];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Import'), '#button_type' => 'primary']];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $upload = $this->getRequest()->files->get('files', [])['file'] ?? NULL;
    if (!$upload || !$upload->isValid()) {
      $form_state->setErrorByName('file', $this->t('Choose a JSON file.'));
      return;
    }
    try {
      $document = json_decode(file_get_contents($upload->getRealPath()), TRUE, 512, JSON_THROW_ON_ERROR);
      $set = \Drupal::entityTypeManager()->getStorage('codedoc_set')->load($form_state->get('set'));
      if (!is_array($document) || ($document['project'] ?? '') !== $set->getIdentifier()) {
        throw new ContractException('The JSON project must match this documentation set.');
      }
      $form_state->set('document', $document);
    }
    catch (\JsonException | ContractException $e) {
      $form_state->setErrorByName('file', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = \Drupal::service('divengine_codedocs.importer')->import($form_state->get('document'), 'auto', (bool) $form_state->getValue('force'));
      $this->messenger()->addStatus($this->t('Documentation: @status.', ['@status' => $result['status']]));
      $form_state->setRedirectUrl($result['snapshot']->toUrl());
    }
    catch (ContractException | \TypeError $e) {
      $this->messenger()->addError($e instanceof \TypeError ? $this->t('Invalid documentation structure.') : $e->getMessage());
      $form_state->setRebuild();
    }
  }

}
