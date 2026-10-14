<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

class ArtifactTypeForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $artifact_type = $this->entity;

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $artifact_type->id(),
      '#machine_name' => [
        'exists' => '\Drupal\divengine_artifact\Entity\ArtifactType::load',
      ],
      '#disabled' => !$artifact_type->isNew(),
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $artifact_type->label(),
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $artifact_type->get('description'),
    ];

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label artifact type.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
