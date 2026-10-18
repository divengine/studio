<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for process roles.
 */
class ProcessRoleForm extends ProcessConfigForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\ProcessRole $entity */
    $entity = $this->entity;
    $form['disciplines'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Disciplines'),
      '#options' => $this->referenceOptions('process_discipline', $entity->getDisciplines()),
      '#default_value' => $entity->getDisciplines(),
      '#description' => $this->t('A required discipline counts as staffed when someone plays one of its roles.'),
    ];
    return $form;
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $form_state->setValue('disciplines', array_values(array_filter($form_state->getValue('disciplines', []))));
    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

}
