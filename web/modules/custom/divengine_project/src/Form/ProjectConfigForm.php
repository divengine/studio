<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_project\Entity\ProjectStage;
use Drupal\divengine_project\Entity\TaskType;

/**
 * Form for task stages and task types.
 */
class ProjectConfigForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\divengine_project\Entity\ProjectConfigEntityBase $entity */
    $entity = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => ['exists' => [get_class($entity), 'load']],
      '#disabled' => !$entity->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->getDescription(),
      '#rows' => 2,
    ];
    if ($entity instanceof ProjectStage) {
      $form['category'] = [
        '#type' => 'radios',
        '#title' => $this->t('Category'),
        '#options' => ProjectStage::categories(),
        '#default_value' => $entity->getCategory(),
        '#description' => $this->t('What the stage means, whatever its name. Tasks in a Done or Cancelled stage are closed.'),
        '#required' => TRUE,
      ];
      $form['folded'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Folded on boards'),
        '#default_value' => $entity->isFolded(),
        '#description' => $this->t('Boards show the column collapsed until someone opens it.'),
      ];
    }
    if ($entity instanceof TaskType) {
      $form['color'] = [
        '#type' => 'color',
        '#title' => $this->t('Color'),
        '#default_value' => $entity->getColor(),
      ];
    }
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Order'),
      '#default_value' => $entity->getWeight(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->status(),
    ];
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
