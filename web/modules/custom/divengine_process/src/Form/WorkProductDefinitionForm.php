<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Form for work product definitions.
 */
class WorkProductDefinitionForm extends ProcessConfigForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\WorkProductDefinition $entity */
    $entity = $this->entity;
    $form['discipline'] = [
      '#type' => 'select',
      '#title' => $this->t('Discipline'),
      '#options' => $this->referenceOptions('process_discipline', [$entity->getDiscipline()]),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $entity->getDiscipline(),
    ];
    return $form;
  }

}
