<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\divengine_project\Entity\ProjectTask;

/**
 * Loads tasks and renders them the same way everywhere: tables, type
 * markers, assignees and progress bars.
 */
class TaskTable {

  use StringTranslationTrait;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Visible tasks matching conditions, in board order.
   *
   * @return \Drupal\divengine_project\Entity\ProjectTask[]
   */
  public function load(array $conditions, array $sort = ['weight' => 'ASC', 'id' => 'ASC']): array {
    $storage = $this->entityTypeManager->getStorage('project_task');
    $query = $storage->getQuery()->accessCheck(TRUE);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value, is_array($value) ? 'IN' : '=');
    }
    foreach ($sort as $field => $direction) {
      $query->sort($field, $direction);
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * A table of tasks.
   */
  public function table(array $tasks, $empty, bool $with_subtasks = FALSE, bool $with_project = FALSE): array {
    $rows = [];
    foreach ($tasks as $task) {
      $row = [
        ['data' => ['#plain_text' => (string) $task->get('code')->value]],
        ['data' => ['type' => $this->type($task), 'link' => $task->toLink($task->get('name')->value)->toRenderable()]],
      ];
      if ($with_project) {
        $row[] = ['data' => ['#plain_text' => (string) $task->getProject()?->label()]];
      }
      $row[] = ['data' => $this->stage($task)];
      $row[] = ['data' => ['#plain_text' => (string) (ProjectTask::priorities()[$task->get('priority')->value] ?? '')]];
      $row[] = ['data' => $this->assignees($task)];
      $row[] = ['data' => $this->deadline($task)];
      if ($with_subtasks) {
        $subtasks = $this->load(['parent' => $task->id()]);
        $row[] = ['data' => ['#plain_text' => $subtasks ? count(array_filter($subtasks, fn (ProjectTask $t) => $t->isClosed())) . ' / ' . count($subtasks) : '']];
      }
      $rows[] = $row;
    }
    $header = [$this->t('Code'), $this->t('Task')];
    if ($with_project) {
      $header[] = $this->t('Project');
    }
    $header = array_merge($header, [$this->t('Stage'), $this->t('Priority'), $this->t('Assignees'), $this->t('Deadline')]);
    if ($with_subtasks) {
      $header[] = $this->t('Subtasks');
    }
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $empty, '#attributes' => ['class' => ['dv-task-table']]];
  }

  /**
   * The coloured marker of a task type.
   */
  public function type(ProjectTask $task): array {
    $type = $task->get('type')->entity;
    if (!$type) {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $type->label(),
      '#attributes' => ['class' => ['dv-task-type'], 'style' => '--dv-task-type:' . $type->getColor()],
    ];
  }

  public function stage(ProjectTask $task): array {
    $stage = $task->getStage();
    return $stage ? ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => $stage->label(), 'tone' => $stage->tone()]] : [];
  }

  /**
   * Assignees as initials, with their names on hover.
   */
  public function assignees(ProjectTask $task): array {
    $build = ['#type' => 'container', '#attributes' => ['class' => ['dv-avatars']]];
    foreach ($task->get('assignees') as $delta => $item) {
      if ($user = $item->entity) {
        $name = $user->getDisplayName();
        $initials = mb_strtoupper(implode('', array_map(fn ($part) => mb_substr($part, 0, 1), array_slice(preg_split('/[\s._-]+/', $name) ?: [$name], 0, 2))));
        $build[$delta] = ['#type' => 'html_tag', '#tag' => 'span', '#value' => $initials, '#attributes' => ['class' => ['dv-avatar'], 'title' => $name]];
      }
    }
    return $build;
  }

  public function deadline(ProjectTask $task): array {
    $deadline = (string) $task->get('deadline')->value;
    if ($deadline === '') {
      return [];
    }
    return ['#type' => 'html_tag', '#tag' => 'span', '#value' => $deadline, '#attributes' => ['class' => array_filter(['dv-deadline', $task->isOverdue() ? 'dv-deadline--overdue' : NULL])]];
  }

  /**
   * "x of y done" with a bar.
   */
  public function progress(int $done, int $total): array {
    $percent = $total ? round($done * 100 / $total) : 0;
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-progress']],
      'bar' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['dv-progress__track']],
        'fill' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['dv-progress__fill'], 'style' => 'width:' . $percent . '%']],
      ],
      'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('@done of @total done', ['@done' => $done, '@total' => $total]), '#attributes' => ['class' => ['dv-progress__label']]],
    ];
  }

}
