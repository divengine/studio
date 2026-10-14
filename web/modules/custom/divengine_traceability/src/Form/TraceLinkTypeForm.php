<?php

declare(strict_types=1);

namespace Drupal\divengine_traceability\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_traceability\Entity\TraceLinkType;

/**
 * Form for trace link types.
 */
class TraceLinkTypeForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_traceability\Entity\TraceLinkType $type */
    $type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Read from source to target, e.g. "Realizes".'),
      '#maxlength' => 128,
      '#default_value' => $type->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $type->id(),
      '#machine_name' => [
        'exists' => [TraceLinkType::class, 'load'],
        'source' => ['label'],
      ],
      '#disabled' => !$type->isNew(),
    ];
    $form['inverse_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Inverse label'),
      '#description' => $this->t('Read from target to source, e.g. "Is realized by".'),
      '#maxlength' => 128,
      '#default_value' => $type->getInverseLabel(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $type->getDescription(),
    ];
    $rules_help = $this->t('One pattern per line: an entity type ("artifact") or an entity type and bundle ("artifact:requirement"). Leave empty to accept any entity.');
    $form['source_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed sources'),
      '#description' => $rules_help,
      '#default_value' => implode("\n", $type->getSourceRules()),
      '#rows' => 3,
    ];
    $form['target_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed targets'),
      '#description' => $rules_help,
      '#default_value' => implode("\n", $type->getTargetRules()),
      '#rows' => 3,
    ];
    $form['same_kind'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Both ends must be the same kind'),
      '#description' => $this->t('Same entity type and bundle, as for "Supersedes".'),
      '#default_value' => $type->requiresSameKind(),
    ];
    return $form;
  }

  public function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    foreach (['source_rules', 'target_rules'] as $key) {
      $lines = preg_split('/\R/', (string) $form_state->getValue($key));
      $entity->set($key, array_values(array_filter(array_map('trim', $lines))));
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label trace link type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
