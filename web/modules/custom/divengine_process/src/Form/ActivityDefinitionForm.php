<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for activity definitions.
 */
class ActivityDefinitionForm extends ProcessConfigForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\ActivityDefinition $entity */
    $entity = $this->entity;
    $roles = $this->referenceOptions('process_role', [$entity->getResponsibleRole(), ...$entity->getParticipantRoles()]);
    $definitions = $this->referenceOptions('work_product_definition', [...$entity->getInputDefinitions(), ...$entity->getOutputDefinitions()]);
    $form['discipline'] = [
      '#type' => 'select',
      '#title' => $this->t('Discipline'),
      '#options' => $this->referenceOptions('process_discipline', [$entity->getDiscipline()]),
      '#default_value' => $entity->getDiscipline(),
      '#required' => TRUE,
    ];
    $form['responsible_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Responsible role'),
      '#options' => $roles,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $entity->getResponsibleRole(),
    ];
    $form['participant_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Participating roles'),
      '#options' => $roles,
      '#default_value' => $entity->getParticipantRoles(),
    ];
    $form['input_definitions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Expected inputs'),
      '#options' => $definitions,
      '#default_value' => $entity->getInputDefinitions(),
    ];
    $form['output_definitions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Expected outputs'),
      '#options' => $definitions,
      '#default_value' => $entity->getOutputDefinitions(),
      '#description' => $this->t('Completing an activity without one of its expected outputs requires explaining the deviation.'),
    ];
    $form['guidance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Guidance'),
      '#default_value' => $entity->getGuidance(),
      '#rows' => 3,
    ];
    return $form;
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    foreach (['participant_roles', 'input_definitions', 'output_definitions'] as $key) {
      $form_state->setValue($key, array_values(array_filter($form_state->getValue($key, []))));
    }
    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

}
