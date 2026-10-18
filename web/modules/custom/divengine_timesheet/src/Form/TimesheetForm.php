<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form of a timesheet entry.
 *
 * Opened from a task (?task=ID) the task and project are set. Only people
 * who supervise timesheets choose whose time it is.
 */
class TimesheetForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\divengine_timesheet\Entity\Timesheet $entry */
    $entry = $this->entity;
    $query = $this->getRequest()->query;
    if ($entry->isNew()) {
      if (($id = $query->get('task')) && ($task = $this->entityTypeManager->getStorage('project_task')->load($id)) && $task->access('view')) {
        $entry->set('task', $task->id());
        $entry->set('project', $task->get('project')->target_id);
      }
      elseif (($id = $query->get('project')) && ($project = $this->entityTypeManager->getStorage('project')->load($id)) && $project->access('view')) {
        $entry->set('project', $project->id());
      }
    }
    $form = parent::form($form, $form_state);
    $account = $this->currentUser();
    $form['uid']['#access'] = $account->hasPermission('manage timesheets') || $account->hasPermission('administer projects');
    $form['project']['#description'] = $this->t('Leave as is when you pick a task: the task decides the project.');
    return $form;
  }

  public function buildEntity(array $form, FormStateInterface $form_state): EntityInterface {
    /** @var \Drupal\divengine_timesheet\Entity\Timesheet $entry */
    $entry = parent::buildEntity($form, $form_state);
    if ($task = $entry->get('task')->entity) {
      $entry->set('project', $task->get('project')->target_id);
    }
    return $entry;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Logged %entry.', ['%entry' => $this->entity->label()]));
    $target = $this->entity->get('task')->entity ?? $this->entity->get('project')->entity;
    if ($target && !$this->getRequest()->query->has('destination')) {
      $form_state->setRedirectUrl($target->toUrl());
    }
    return $result;
  }

}
