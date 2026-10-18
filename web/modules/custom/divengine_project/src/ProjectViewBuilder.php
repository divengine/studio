<?php

declare(strict_types=1);

namespace Drupal\divengine_project;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pages of projects and tasks.
 *
 * Each opens with a status bar (code, status, facts and the actions that
 * apply now), then the numbers and the related records.
 */
class ProjectViewBuilder extends EntityViewBuilder {

  /**
   * Trace relations between tasks, shown as "related tasks".
   */
  const TASK_RELATIONS = ['relates_to', 'blocks', 'duplicates', 'depends_on'];

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountInterface $currentUser;

  protected TaskTable $tasks;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->tasks = $container->get('divengine_project.task_table');
    return $instance;
  }

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    if ($view_mode !== 'full' && $view_mode !== 'default') {
      return;
    }
    foreach ($entities as $id => $entity) {
      // The page shows the fields in its own sections; traces and the
      // comment thread stay.
      foreach (array_keys($entity->getFieldDefinitions()) as $field_name) {
        if ($field_name !== 'comments') {
          unset($build[$id][$field_name]);
        }
      }
      $build[$id] += $entity instanceof Project ? $this->project($entity) : $this->task($entity);
      $build[$id]['#attached']['library'][] = 'divengine_project/project';
      $build[$id]['#cache']['tags'][] = 'project_list';
      $build[$id]['#cache']['tags'][] = 'project_task_list';
      $build[$id]['#cache']['tags'][] = 'trace_link_list';
      $build[$id]['#cache']['contexts'][] = 'user';
    }
  }

  // ------------------------------------------------------------- Project.

  protected function project(Project $project): array {
    $tasks = $this->tasks->load(['project' => $project->id()]);
    $open = array_filter($tasks, fn (ProjectTask $t) => !$t->isClosed());
    $started = array_filter($open, fn (ProjectTask $t) => $t->getStage()?->getCategory() === 'in_progress');
    $overdue = array_filter($open, fn (ProjectTask $t) => $t->isOverdue());
    $children = $this->children('project', ['parent' => $project->id()], 'name');

    $actions = [];
    $task_handler = $this->entityTypeManager->getAccessControlHandler('project_task');
    if ($task_handler->createAccess()) {
      $actions[] = $this->button($this->t('New task'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['project' => $project->id()]]), TRUE);
    }
    $actions[] = $this->button($this->t('Board'), Url::fromRoute('divengine_project.board', ['project' => $project->id()]));
    if ($this->entityTypeManager->getAccessControlHandler('project')->createAccess()) {
      $actions[] = $this->button($this->t('Add subproject'), Url::fromRoute('entity.project.add_form', [], ['query' => ['parent' => $project->id()]]));
    }
    if ($project->access('update')) {
      $actions[] = $this->button($this->t('Edit'), $project->toUrl('edit-form'));
    }
    $dates = trim(($project->get('start_date')->value ?? '') . ' → ' . ($project->get('end_date')->value ?? ''), ' →');
    $build['status'] = $this->statusBar($project->getKey(), (string) Project::statuses()[$project->getStatus()], $this->statusTone($project->getStatus()), [
      (string) $this->t('Manager') => $project->get('manager')->entity?->getDisplayName(),
      (string) $this->t('Parent project') => $project->get('parent')->entity?->label(),
      (string) $this->t('Members') => (string) count($project->get('members')),
      (string) $this->t('Dates') => $dates,
    ], $actions) + ['#weight' => -100];

    $build['cards'] = $this->cards([
      [$this->t('Open tasks'), count($open), $this->t('@n in total', ['@n' => count($tasks)]), 'info', Url::fromRoute('divengine_project.tasks', ['project' => $project->id()])],
      [$this->t('In progress'), count($started), '', 'warning', NULL],
      [$this->t('Done'), count($tasks) - count($open), $tasks ? $this->t('@p% of all tasks', ['@p' => round((count($tasks) - count($open)) * 100 / count($tasks))]) : '', 'success', NULL],
      [$this->t('Overdue'), count($overdue), '', $overdue ? 'danger' : 'neutral', NULL],
      [$this->t('Subprojects'), count($children), '', 'neutral', NULL],
    ]) + ['#weight' => -90];

    if (!$project->get('description')->isEmpty()) {
      $build['description'] = $this->section($this->t('Description'), 0, $this->text($project->get('description'))) + ['#weight' => -80];
    }

    $rows = [];
    foreach ($children as $child) {
      $child_tasks = $this->tasks->load(['project' => $child->id()]);
      $rows[] = [
        ['data' => ['#plain_text' => $child->getKey()]],
        ['data' => $child->toLink($child->get('name')->value)->toRenderable()],
        ['data' => ['#plain_text' => (string) $child->get('manager')->entity?->getDisplayName()]],
        ['data' => $this->badge((string) Project::statuses()[$child->getStatus()], $this->statusTone($child->getStatus()))],
        ['data' => ['#plain_text' => (string) count(array_filter($child_tasks, fn (ProjectTask $t) => !$t->isClosed()))]],
      ];
    }
    if ($rows) {
      $build['subprojects'] = $this->section($this->t('Subprojects'), count($rows), $this->table([$this->t('Key'), $this->t('Project'), $this->t('Manager'), $this->t('Status'), $this->t('Open tasks')], $rows, '')) + ['#weight' => -70];
    }

    $top = array_filter($open, fn (ProjectTask $t) => $t->get('parent')->isEmpty());
    $add = $task_handler->createAccess() ? $this->button($this->t('New task'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['project' => $project->id()]])) : NULL;
    $build['tasks'] = $this->section($this->t('Open tasks'), count($top), $this->tasks->table($top, $this->t('No open task. Create the first one, or open the board.'), TRUE), $add) + ['#weight' => -60];
    return $build;
  }

  // ---------------------------------------------------------------- Task.

  protected function task(ProjectTask $task): array {
    $project = $task->getProject();
    $stage = $task->getStage();
    $actions = [];
    if ($task->access('update') && $project) {
      foreach ($project->getStages() as $id => $candidate) {
        if ($id !== $stage?->id()) {
          $actions[] = $this->button($candidate->label(), Url::fromRoute('divengine_project.move', ['project_task' => $task->id(), 'stage' => $id], ['query' => ['destination' => $task->toUrl()->toString()]]), FALSE, $this->t('Move to @stage', ['@stage' => $candidate->label()]));
        }
      }
    }
    if ($this->entityTypeManager->getAccessControlHandler('project_task')->createAccess()) {
      $actions[] = $this->button($this->t('Add subtask'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['parent' => $task->id()]]), TRUE);
    }
    if ($task->access('update')) {
      $actions[] = $this->button($this->t('Edit'), $task->toUrl('edit-form'));
    }
    $type = $task->get('type')->entity;
    $build['status'] = $this->statusBar((string) $task->get('code')->value, (string) ($stage?->label() ?? $this->t('No stage')), $stage?->tone() ?? 'neutral', [
      (string) $this->t('Project') => $project?->label(),
      (string) $this->t('Type') => $type?->label(),
      (string) $this->t('Priority') => (string) (ProjectTask::priorities()[$task->get('priority')->value] ?? ''),
      (string) $this->t('Assignees') => implode(', ', array_map(fn ($item) => $item->entity?->getDisplayName(), iterator_to_array($task->get('assignees')))),
      (string) $this->t('Deadline') => $task->get('deadline')->value ? $task->get('deadline')->value . ($task->isOverdue() ? ' · ' . $this->t('overdue') : '') : NULL,
      (string) $this->t('Allocated') => $task->get('estimate')->value ? $this->t('@h h', ['@h' => (float) $task->get('estimate')->value]) : NULL,
      (string) $this->t('Parent task') => $task->get('parent')->entity?->label(),
    ], $actions) + ['#weight' => -100];

    if ($task->isOverdue()) {
      $build['overdue'] = ['#theme' => 'status_messages', '#message_list' => ['warning' => [$this->t('This task is past its deadline.')]], '#status_headings' => ['warning' => $this->t('Warning message')], '#weight' => -95];
    }

    $build['description'] = $this->section($this->t('Description'), 0, $task->get('description')->isEmpty()
      ? ['#markup' => '<p>' . $this->t('No description yet.') . '</p>']
      : $this->text($task->get('description'))) + ['#weight' => -80];

    $subtasks = $this->tasks->load(['parent' => $task->id()]);
    $done = count(array_filter($subtasks, fn (ProjectTask $t) => $t->isClosed()));
    $content = [];
    if ($subtasks) {
      $content['progress'] = $this->tasks->progress($done, count($subtasks));
    }
    $content['table'] = $this->tasks->table($subtasks, $this->t('No subtask. Split the work with "Add subtask".'));
    $add = $this->entityTypeManager->getAccessControlHandler('project_task')->createAccess()
      ? $this->button($this->t('Add subtask'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['parent' => $task->id()]]))
      : NULL;
    $build['subtasks'] = $this->section($this->t('Subtasks'), count($subtasks), $content, $add) + ['#weight' => -70];

    $build['related'] = $this->related($task) + ['#weight' => -60];

    $facts = [
      (string) $this->t('Reporter') => $task->getOwner()?->getDisplayName(),
      (string) $this->t('Tags') => implode(', ', array_map(fn ($item) => $item->entity?->label(), iterator_to_array($task->get('tags')))),
      (string) $this->t('Start date') => $task->get('start_date')->value,
      (string) $this->t('Created') => $this->date($task->get('created')->value),
      (string) $this->t('Closed on') => $this->date($task->get('date_closed')->value),
    ];
    $rows = [];
    foreach ($facts as $label => $value) {
      if ((string) $value !== '') {
        $rows[] = ['label' => $label, 'value' => (string) $value];
      }
    }
    $build['details'] = $this->section($this->t('Details'), 0, ['#type' => 'component', '#component' => 'divengine_core:fact-list', '#props' => ['rows' => $rows]]) + ['#weight' => -50];
    return $build;
  }

  /**
   * Tasks related to this one by a trace, read from this task's side.
   */
  protected function related(ProjectTask $task): array {
    $storage = $this->entityTypeManager->getStorage('trace_link');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('link_type', static::TASK_RELATIONS, 'IN');
    $query->condition($query->orConditionGroup()
      ->condition($query->andConditionGroup()->condition('source_entity_type', 'project_task')->condition('source_entity_id', $task->id())->condition('target_entity_type', 'project_task'))
      ->condition($query->andConditionGroup()->condition('target_entity_type', 'project_task')->condition('target_entity_id', $task->id())->condition('source_entity_type', 'project_task')));
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $link) {
      $outgoing = (string) $link->getSourceEntityId() === (string) $task->id();
      $other = $outgoing ? $link->getTargetEntity() : $link->getSourceEntity();
      if (!$other instanceof ProjectTask || !$other->access('view')) {
        continue;
      }
      $type = $link->getLinkType();
      $relation = $outgoing ? $type?->label() : ($type?->getInverseLabel() ?: $type?->label());
      $rows[] = [
        ['data' => ['#plain_text' => (string) $relation]],
        ['data' => $other->toLink()->toRenderable()],
        ['data' => $this->badge((string) ($other->getStage()?->label() ?? ''), $other->getStage()?->tone() ?? 'neutral')],
        ['data' => ['#plain_text' => (string) $other->getProject()?->label()]],
      ];
    }
    $add = Url::fromRoute('entity.trace_link.add_form', [], ['query' => [
      'source' => 'project_task/' . $task->id(),
      'type' => 'relates_to',
      'destination' => $task->toUrl()->toString(),
    ]]);
    $button = $add->access($this->currentUser) ? $this->button($this->t('Link a task'), $add) : NULL;
    return $this->section($this->t('Related tasks'), count($rows), $this->table([$this->t('Relation'), $this->t('Task'), $this->t('Stage'), $this->t('Project')], $rows, $this->t('No related task. Use "Link a task" for "relates to", "blocks" or "duplicates".')), $button);
  }

  // -------------------------------------------------------------- Shared.

  protected function children(string $entity_type, array $conditions, string $sort): array {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()->accessCheck(TRUE)->sort($sort);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return $storage->loadMultiple($query->execute());
  }

  protected function statusTone(string $status): string {
    return match ($status) {
      'active' => 'success',
      'on_hold' => 'warning',
      default => 'neutral',
    };
  }

  protected function statusBar(string $code, string $state, string $tone, array $facts, array $actions): array {
    $rows = [];
    foreach ($facts as $label => $value) {
      if ($value !== NULL && (string) $value !== '') {
        $rows[] = ['label' => (string) $label, 'value' => (string) $value];
      }
    }
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:status-bar',
      '#props' => ['code' => $code, 'facts' => $rows],
      '#slots' => array_filter([
        'badge' => $this->badge($state, $tone),
        'actions' => $actions ? ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions']], 'actions' => $actions] : NULL,
      ]),
    ];
  }

  protected function cards(array $cards): array {
    $build = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach ($cards as $i => [$label, $value, $hint, $tone, $url]) {
      $build[$i] = [
        '#type' => 'component',
        '#component' => 'divengine_core:stat-card',
        '#props' => array_filter(['label' => (string) $label, 'value' => (string) $value, 'hint' => (string) $hint, 'tone' => $tone, 'url' => $url?->toString()], fn ($v) => $v !== NULL && $v !== ''),
      ];
    }
    return $build;
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function table(array $header, array $rows, $empty): array {
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $empty];
  }

  protected function badge(string $label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => $label, 'tone' => $tone]];
  }

  protected function text($items): array {
    return ['#type' => 'processed_text', '#text' => $items->value, '#format' => $items->format];
  }

  protected function button($label, Url $url, bool $primary = FALSE, $title = NULL): array {
    $attributes = ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])];
    if ($title) {
      $attributes['title'] = (string) $title;
    }
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => $attributes];
  }

  protected function date($timestamp): string {
    return $timestamp ? \Drupal::service('date.formatter')->format((int) $timestamp, 'short') : '';
  }

}
