<?php

declare(strict_types=1);

namespace Drupal\divengine_external\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_external\ExternalReferenceException;
use Drupal\divengine_external\ExternalReferences;
use Drupal\divengine_external\InvalidExternalUrlException;
use Drupal\divengine_traceability\Entity\TraceLinkType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Links a record of a Development to external work in one step.
 *
 * The link is registered as an external reference of the record's
 * Development, or the one already registered is reused, and a trace link of
 * the chosen type joins them.
 */
class LinkExternalWorkForm extends FormBase {

  protected ExternalReferences $references;

  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->references = $container->get('divengine_external.references');
    return $instance;
  }

  public function getFormId(): string {
    return 'divengine_external_link_form';
  }

  /**
   * The record the route names, if it exists and can have traces.
   */
  protected static function record(string $entity_type_id, string $entity_id): ?ContentEntityInterface {
    $manager = \Drupal::entityTypeManager();
    if ($entity_type_id === 'external_reference' || !in_array($entity_type_id, \Drupal::service('divengine_traceability.trace_panel')->endTypes(), TRUE)) {
      return NULL;
    }
    $entity = $manager->getStorage($entity_type_id)->load($entity_id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Who may link external work to the record.
   */
  public static function access(string $entity_type_id, string $entity_id, AccountInterface $account): AccessResultInterface {
    $record = static::record($entity_type_id, $entity_id);
    if (!$record) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $development_id = \Drupal::service('divengine_external.references')->developmentOf($record);
    $development = $development_id ? \Drupal::entityTypeManager()->getStorage('development')->load($development_id) : NULL;
    if (!$development) {
      return AccessResult::forbidden()->addCacheableDependency($record);
    }
    return $record->access('view', $account, TRUE)
      ->andIf($development->access('update', $account, TRUE))
      ->andIf(\Drupal::entityTypeManager()->getAccessControlHandler('external_reference')->createAccess(NULL, $account, [], TRUE))
      ->andIf(\Drupal::entityTypeManager()->getAccessControlHandler('trace_link')->createAccess(NULL, $account, [], TRUE));
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $entity_type_id = '', string $entity_id = ''): array {
    $record = static::record($entity_type_id, $entity_id);
    if (!$record) {
      throw new NotFoundHttpException();
    }
    $form_state->set('record', [$entity_type_id, $entity_id]);
    $form['#title'] = $this->t('Link external work to %record', ['%record' => $record->label()]);
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Link'),
      '#description' => $this->t('A GitHub issue, pull request, commit or repository, or any web page. Studio stores the link, not a copy of the work.'),
      '#required' => TRUE,
      '#maxlength' => 2048,
    ];
    $form['link_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Relationship'),
      '#options' => TraceLinkType::allowedValues(),
      '#default_value' => 'implements',
      '#required' => TRUE,
    ];
    $form['direction'] = [
      '#type' => 'radios',
      '#title' => $this->t('Direction'),
      '#options' => [
        'reference' => $this->t('The external work → this record (a pull request implements or verifies it)'),
        'record' => $this->t('This record → the external work (it references or depends on it)'),
      ],
      '#default_value' => 'reference',
      '#required' => TRUE,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Only for work not referenced yet. Left empty, it is read from the link (owner/repository#12).'),
      '#maxlength' => 255,
    ];
    $form['rationale'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rationale'),
      '#rows' => 2,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Link'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->references->identify((string) $form_state->getValue('url'));
    }
    catch (InvalidExternalUrlException $e) {
      $form_state->setErrorByName('url', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$entity_type_id, $entity_id] = $form_state->get('record');
    $record = static::record($entity_type_id, $entity_id);
    $account = $this->currentUser();
    try {
      [$reference, $created] = $this->references->resolve($this->references->developmentOf($record), (string) $form_state->getValue('url'), $account, trim((string) $form_state->getValue('label')));
      [$link, $linked] = $this->references->link($record, $reference, (string) $form_state->getValue('link_type'), $form_state->getValue('direction') === 'reference', $account, trim((string) $form_state->getValue('rationale')));
    }
    catch (ExternalReferenceException | InvalidExternalUrlException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($created
      ? $this->t('Registered %reference.', ['%reference' => $reference->label()])
      : $this->t('%reference was already referenced in this Development; it was reused.', ['%reference' => $reference->label()]));
    $this->messenger()->addStatus($linked
      ? $this->t('Saved the trace link %label.', ['%label' => $link->label()])
      : $this->t('The trace link %label already existed.', ['%label' => $link->label()]));
    $form_state->setRedirectUrl($record->toUrl());
  }

}
