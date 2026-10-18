<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\divengine_core\Form\EntityFormLayout;
use Drupal\divengine_project\Entity\ProjectTask;

/**
 * Form for projects and tasks.
 *
 * "Add subproject" passes ?parent=ID; "Add task" passes ?project=ID and
 * "Add subtask" ?parent=ID (the project comes from the parent task). The
 * stage choices are the columns of the task's project.
 */
class ProjectEntityForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entity = $this->entity;
    $query = $this->getRequest()->query;
    if ($entity->isNew()) {
      $type = $entity->getEntityTypeId();
      if (($id = $query->get('parent')) && ($parent = $this->entityTypeManager->getStorage($type)->load($id)) && $parent->access('view')) {
        $entity->set('parent', $parent->id());
        if ($entity instanceof ProjectTask) {
          $entity->set('project', $parent->get('project')->target_id);
        }
      }
      if ($entity instanceof ProjectTask && ($id = $query->get('project')) && ($project = $this->entityTypeManager->getStorage('project')->load($id)) && $project->access('view')) {
        $entity->set('project', $project->id());
      }
      if ($entity instanceof ProjectTask && ($id = $query->get('stage'))) {
        $entity->set('stage', $id);
      }
    }
    $form = parent::form($form, $form_state);

    if ($entity instanceof ProjectTask) {
      $project = $entity->getProject();
      if ($project) {
        $form['project']['#access'] = FALSE;
        if (isset($form['stage']['widget']['#options'])) {
          $form['stage']['widget']['#options'] = array_intersect_key($form['stage']['widget']['#options'], ['_none' => TRUE] + $project->getStages());
          if ($entity->get('stage')->isEmpty() && ($initial = $project->getInitialStage())) {
            $form['stage']['widget']['#default_value'] = [$initial->id()];
          }
        }
      }
      if (!$entity->get('parent')->isEmpty() && $entity->isNew()) {
        $form['parent']['#access'] = FALSE;
      }
      if (isset($form['revision_log'])) {
        $form['revision_log']['#access'] = !$entity->isNew();
      }
      EntityFormLayout::apply($form, $entity, ['uid', 'type', 'stage', 'priority', 'assignees', 'tags', 'start_date', 'deadline', 'estimate', 'parent'], ['description']);
    }
    else {
      if (!$entity->get('parent')->isEmpty() && $entity->isNew()) {
        $form['parent']['#access'] = FALSE;
      }
      EntityFormLayout::apply($form, $entity, ['uid', 'status', 'manager', 'members', 'start_date', 'end_date', 'parent'], ['description', 'stages']);
    }
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus($result === SAVED_NEW ? $this->t('Created %label.', $args) : $this->t('Saved %label.', $args));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
