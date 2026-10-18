<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\TaskType;

/**
 * Filters of the task list of a project, as a GET form.
 */
class TaskFilterForm extends FormBase {

  public function getFormId(): string {
    return 'divengine_project_task_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Project $project = NULL): array {
    $q = $this->getRequest()->query;
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'cleanUp'];
    $form['#attributes']['class'][] = 'dv-filter-form';
    $form['text'] = ['#type' => 'search', '#title' => $this->t('Code or title'), '#default_value' => $q->get('text'), '#size' => 24];
    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('Stage'),
      '#options' => ['open' => $this->t('Open'), 'closed' => $this->t('Closed'), 'all' => $this->t('All')] + array_map(fn ($s) => $s->label(), $project?->getStages() ?? []),
      '#default_value' => $q->get('state', 'open'),
    ];
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => array_map(fn ($t) => $t->label(), TaskType::loadOrdered()),
      '#empty_option' => $this->t('Any'),
      '#default_value' => $q->get('type'),
    ];
    $form['mine'] = ['#type' => 'checkbox', '#title' => $this->t('Assigned to me'), '#default_value' => (bool) $q->get('mine'), '#return_value' => 1];
    $form['apply'] = ['#type' => 'submit', '#value' => $this->t('Filter'), '#name' => ''];
    return $form;
  }

  public static function cleanUp(array $form): array {
    unset($form['form_build_id'], $form['form_id'], $form['form_token']);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
