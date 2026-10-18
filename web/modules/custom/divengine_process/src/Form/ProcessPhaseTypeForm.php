<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_process\Vocabulary;

/**
 * Form for lifecycle phase types.
 */
class ProcessPhaseTypeForm extends ProcessConfigForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_process\Entity\ProcessPhaseType $entity */
    $entity = $this->entity;
    $form['milestone_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Closing milestone'),
      '#options' => Vocabulary::milestoneTypes(),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $entity->getMilestoneType(),
      '#description' => $this->t('The lifecycle milestone that concludes this phase.'),
    ];
    return $form;
  }

}
