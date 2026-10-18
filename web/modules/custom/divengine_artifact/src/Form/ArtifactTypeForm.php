<?php

declare(strict_types=1);

namespace Drupal\divengine_artifact\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

class ArtifactTypeForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $artifact_type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $artifact_type->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $artifact_type->id(),
      '#machine_name' => [
        'exists' => '\Drupal\divengine_artifact\Entity\ArtifactType::load',
        'source' => ['label'],
      ],
      '#disabled' => !$artifact_type->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $artifact_type->get('description'),
    ];

    $form['key_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key prefix'),
      '#default_value' => $artifact_type->getKeyPrefix(),
      '#maxlength' => 16,
      '#size' => 10,
      '#pattern' => '[A-Z][A-Z0-9]*',
      '#description' => $this->t('New artifacts get the next key with this prefix, e.g. REQ gives REQ-0001. Leave empty to type keys by hand.'),
    ];
    $form['new_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create new revision'),
      '#default_value' => $artifact_type->shouldCreateNewRevision(),
      '#description' => $this->t('Artifacts of this type keep their history: every save creates a revision unless the editor unchecks it.'),
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
