<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_database\Contract\AdapterManager;
use Drupal\divengine_database\Contract\ContractException;
use Drupal\divengine_database\Entity\SchemaDatabase;
use Drupal\divengine_database\SnapshotImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports a snapshot file into a database: the Divengine contract (JSON) or
 * any format an adapter knows (SchemaSpy XML).
 */
class ImportSnapshotForm extends FormBase {

  public function __construct(
    protected AdapterManager $adapters,
    protected SnapshotImporter $importer,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_database.adapters'), $container->get('divengine_database.importer'));
  }

  public function getFormId(): string {
    return 'divengine_database_import';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?SchemaDatabase $schema_database = NULL): array {
    $form_state->set('database', $schema_database->id());
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['intro'] = ['#markup' => '<p class="dv-intro">' . $this->t('Import the schema of %database as a new snapshot. Studio reads only the structure: tables, columns, keys, indexes, relationships and routines, never rows.', ['%database' => $schema_database->label()]) . '</p>'];
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Snapshot file'),
      '#description' => $this->t('SchemaSpy writes its XML next to the HTML report (e.g. <code>mydb.mydb.xml</code>). Other analyzers send the Divengine contract (JSON).'),
    ];
    $form['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Format'),
      '#options' => ['auto' => $this->t('Recognize it')] + $this->adapters->options(),
      '#default_value' => 'auto',
    ];
    $form['revision'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revision'),
      '#description' => $this->t('What the schema was captured at: a Git commit, a release, a migration. It names the snapshot; without it, the content hash does.'),
      '#maxlength' => 128,
    ];
    $form['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Import even if the schema did not change'),
    ];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Import'), '#button_type' => 'primary']];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getRequest()->files->get('files', []);
    $upload = $files['file'] ?? NULL;
    if (!$upload || !$upload->isValid()) {
      $form_state->setErrorByName('file', $this->t('Choose the file to import.'));
      return;
    }
    try {
      $payload = $this->adapters->toContract((string) file_get_contents($upload->getRealPath()), (string) $form_state->getValue('format'), $upload->getClientOriginalName());
      $form_state->set('payload', $payload);
    }
    catch (ContractException $e) {
      $form_state->setErrorByName('file', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $database = \Drupal::entityTypeManager()->getStorage('schema_database')->load($form_state->get('database'));
    try {
      $result = $this->importer->import($form_state->get('payload'), $database, ['revision' => trim((string) $form_state->getValue('revision')), 'force' => (bool) $form_state->getValue('force')]);
    }
    catch (ContractException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }
    $result['status'] === 'imported' ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addWarning($result['message']);
    $form_state->setRedirectUrl($result['snapshot']->toUrl());
  }

}
