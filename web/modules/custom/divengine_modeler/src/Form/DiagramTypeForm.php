<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds and edits diagram types.
 */
class DiagramTypeForm extends BundleEntityFormBase {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_modeler\Entity\DiagramType $type */
    $type = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $type->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $type->id(),
      '#maxlength' => 32,
      '#disabled' => !$type->isNew(),
      '#machine_name' => [
        'exists' => ['Drupal\divengine_modeler\Entity\DiagramType', 'load'],
        'source' => ['label'],
      ],
    ];
    $form['notation'] = [
      '#type' => 'select',
      '#title' => $this->t('Notation'),
      '#options' => \Drupal::service('plugin.manager.modeler_notation')->getOptions(),
      '#default_value' => $type->getNotationId(),
      '#required' => TRUE,
      // Existing diagrams are documents of their notation.
      '#disabled' => !$type->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $type->getDescription(),
      '#rows' => 3,
    ];
    $form['new_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create a new revision by default'),
      '#default_value' => $type->shouldCreateNewRevision(),
    ];
    return $this->protectBundleIdElement($form);
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the diagram type %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
