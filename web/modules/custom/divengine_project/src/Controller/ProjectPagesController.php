<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectStage;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_project\TaskTable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Project lists: all projects as a tree, My tasks, the tasks of a project
 * and its Kanban board.
 */
class ProjectPagesController extends ControllerBase {

  protected TaskTable $tasks;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->tasks = $container->get('divengine_project.task_table');
    return $instance;
  }

  /**
   * Every visible project, subprojects indented under their parent.
   */
  public function projects(Request $request): array {
    $show_all = $request->query->get('status') === 'all';
    $storage = $this->entityTypeManager->getStorage('project');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('name');
    if (!$show_all) {
      $query->condition('status', ['active', 'on_hold'], 'IN');
    }
    $projects = $storage->loadMultiple($query->execute());
    $children = [];
    foreach ($projects as $project) {
      $parent = (string) $project->get('parent')->target_id;
      $children[isset($projects[$parent]) ? $parent : ''][] = $project;
    }
    $rows = [];
    $walk = function (string $parent, int $depth) use (&$walk, &$rows, $children) {
      foreach ($children[$parent] ?? [] as $project) {
        $open = $this->tasks->load(['project' => $project->id()]);
        $open = array_filter($open, fn (ProjectTask $t) => !$t->isClosed());
        $rows[] = [
          ['data' => ['#plain_text' => $project->getKey()]],
          ['data' => [
            'indent' => ['#markup' => str_repeat('<span class="dv-indent"></span>', $depth) . ($depth ? '<span class="dv-indent__mark">↳</span> ' : '')],
            'link' => $project->toLink($project->get('name')->value)->toRenderable(),
          ]],
          ['data' => ['#plain_text' => (string) $project->get('manager')->entity?->getDisplayName()]],
          ['data' => $this->badge((string) Project::statuses()[$project->getStatus()], $project->getStatus() === 'active' ? 'success' : ($project->getStatus() === 'on_hold' ? 'warning' : 'neutral'))],
          ['data' => ['#plain_text' => (string) count($open)]],
          ['data' => Link::fromTextAndUrl($this->t('Board'), Url::fromRoute('divengine_project.board', ['project' => $project->id()]))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]]],
        ];
        $walk((string) $project->id(), $depth + 1);
      }
    };
    $walk('', 0);

    $build = $this->page();
    $filters = [];
    foreach (['open' => $this->t('Active and on hold'), 'all' => $this->t('All')] as $key => $label) {
      $filters[] = $this->button($label, Url::fromRoute('divengine_project.projects', [], ['query' => ['status' => $key]]), ($show_all ? 'all' : 'open') === $key);
    }
    $build['#cache']['contexts'][] = 'url.query_args:status';
    $build['filters'] = ['#type' => 'container', '#attributes' => ['class' => ['divengine-process-actions', 'dv-filters']], 'links' => $filters];
    $add = $this->entityTypeManager->getAccessControlHandler('project')->createAccess()
      ? $this->button($this->t('New project'), Url::fromRoute('entity.project.add_form'), TRUE)
      : NULL;
    $build['projects'] = $this->section($this->t('Projects'), count($rows), [
      '#type' => 'table',
      '#header' => [$this->t('Key'), $this->t('Project'), $this->t('Manager'), $this->t('Status'), $this->t('Open tasks'), ''],
      '#rows' => $rows,
      '#empty' => $this->t('No project yet. Projects you own, manage or are a member of appear here.'),
    ], $add);
    return $build;
  }

  /**
   * Open tasks assigned to the user, then the ones they reported.
   */
  public function myTasks(): array {
    $uid = (int) $this->currentUser()->id();
    $open_stages = array_keys(array_filter(ProjectStage::loadOrdered(), fn (ProjectStage $s) => !$s->isClosed()));
    $sort = ['deadline' => 'ASC', 'priority' => 'DESC', 'id' => 'ASC'];
    $assigned = $open_stages ? $this->tasks->load(['assignees' => $uid, 'stage' => $open_stages], $sort) : [];
    $reported = $open_stages ? $this->tasks->load(['uid' => $uid, 'stage' => $open_stages], $sort) : [];
    $reported = array_diff_key($reported, $assigned);
    $overdue = array_filter($assigned, fn (ProjectTask $t) => $t->isOverdue());

    $build = $this->page();
    $build['cards'] = ['#type' => 'container', '#attributes' => ['class' => ['dv-cards']]];
    foreach ([[$this->t('Assigned to me'), count($assigned), 'info'], [$this->t('Overdue'), count($overdue), $overdue ? 'danger' : 'neutral'], [$this->t('Reported by me, still open'), count($reported), 'neutral']] as $i => [$label, $value, $tone]) {
      $build['cards'][$i] = ['#type' => 'component', '#component' => 'divengine_core:stat-card', '#props' => ['label' => (string) $label, 'value' => (string) $value, 'tone' => $tone]];
    }
    $build['assigned'] = $this->section($this->t('Assigned to me'), count($assigned), $this->tasks->table($assigned, $this->t('Nothing assigned to you. Enjoy it while it lasts.'), FALSE, TRUE));
    $build['reported'] = $this->section($this->t('Reported by me'), count($reported), $this->tasks->table($reported, $this->t('No open task reported by you.'), FALSE, TRUE));
    return $build;
  }

  /**
   * The tasks of a project, filtered.
   */
  public function tasks(Project $project, Request $request): array {
    $q = $request->query;
    $conditions = ['project' => $project->id()];
    $stages = $project->getStages();
    $state = $q->get('state', 'open');
    if ($state === 'open') {
      $conditions['stage'] = array_keys(array_filter($stages, fn (ProjectStage $s) => !$s->isClosed())) ?: ['_none'];
    }
    elseif ($state === 'closed') {
      $conditions['stage'] = array_keys(array_filter($stages, fn (ProjectStage $s) => $s->isClosed())) ?: ['_none'];
    }
    elseif (isset($stages[$state])) {
      $conditions['stage'] = $state;
    }
    if ($type = $q->get('type')) {
      $conditions['type'] = $type;
    }
    if ($q->get('mine')) {
      $conditions['assignees'] = (int) $this->currentUser()->id();
    }
    $tasks = $this->tasks->load($conditions, ['priority' => 'DESC', 'deadline' => 'ASC', 'id' => 'ASC']);
    if ($text = trim((string) $q->get('text'))) {
      $tasks = array_filter($tasks, fn (ProjectTask $t) => mb_stripos($t->label(), $text) !== FALSE);
    }

    $build = $this->page();
    $build['#title'] = $this->t('@project: tasks', ['@project' => $project->label()]);
    $build['#cache']['contexts'][] = 'url.query_args';
    $build['filters'] = $this->formBuilder()->getForm('Drupal\divengine_project\Form\TaskFilterForm', $project);
    $add = $this->entityTypeManager->getAccessControlHandler('project_task')->createAccess()
      ? $this->button($this->t('New task'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['project' => $project->id()]]), TRUE)
      : NULL;
    $build['tasks'] = $this->section($this->t('Tasks'), count($tasks), $this->tasks->table($tasks, $this->t('No task matches.'), TRUE), $add);
    return $build;
  }

  /**
   * The Kanban board of a project: one column per stage.
   */
  public function board(Project $project): array {
    $tasks = $this->tasks->load(['project' => $project->id()]);
    $by_stage = [];
    foreach ($tasks as $task) {
      $by_stage[(string) $task->get('stage')->target_id][] = $task;
    }
    $can_create = $this->entityTypeManager->getAccessControlHandler('project_task')->createAccess();
    $build = $this->page();
    $build['#title'] = $this->t('@project: board', ['@project' => $project->label()]);
    $build['#attached']['library'][] = 'divengine_project/board';
    $build['toolbar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['divengine-process-actions', 'dv-board-toolbar']],
      'project' => $this->button($this->t('Project'), $project->toUrl()),
      'list' => $this->button($this->t('List'), Url::fromRoute('divengine_project.tasks', ['project' => $project->id()])),
      'new' => $can_create ? $this->button($this->t('New task'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['project' => $project->id()]]), TRUE) : [],
    ];
    $board = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dv-board'],
        'data-dv-board' => Url::fromRoute('divengine_project.board_move', ['project' => $project->id()])->toString(),
      ],
    ];
    foreach ($project->getStages() as $id => $stage) {
      $cards = [];
      foreach ($by_stage[$id] ?? [] as $task) {
        $cards[$task->id()] = $this->card($task);
      }
      $board[$id] = [
        '#type' => 'html_tag',
        '#tag' => 'details',
        '#attributes' => array_filter([
          'class' => ['dv-board__column', 'dv-board__column--' . $stage->getCategory()],
          'open' => $stage->isFolded() && !$cards ? NULL : 'open',
        ]),
        'summary' => [
          '#type' => 'html_tag',
          '#tag' => 'summary',
          '#attributes' => ['class' => ['dv-board__header']],
          'title' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $stage->label(), '#attributes' => ['class' => ['dv-board__title']]],
          'count' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => (string) count($cards), '#attributes' => ['class' => ['dv-board__count']]],
        ],
        'cards' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dv-board__cards'], 'data-dv-stage' => $id],
        ] + $cards,
        'add' => $can_create ? Link::fromTextAndUrl($this->t('+ Add a task'), Url::fromRoute('entity.project_task.add_form', [], ['query' => ['project' => $project->id(), 'stage' => $id]]))->toRenderable() + ['#attributes' => ['class' => ['dv-board__add']]] : [],
      ];
    }
    $build['board'] = $board;
    return $build;
  }

  /**
   * A card of the board.
   */
  protected function card(ProjectTask $task): array {
    $subtasks = $this->tasks->load(['parent' => $task->id()]);
    $done = count(array_filter($subtasks, fn (ProjectTask $t) => $t->isClosed()));
    $meta = array_filter([
      'priority' => (int) $task->get('priority')->value >= 2 ? ['#type' => 'html_tag', '#tag' => 'span', '#value' => ProjectTask::priorities()[$task->get('priority')->value], '#attributes' => ['class' => ['dv-card__priority', 'dv-card__priority--' . $task->get('priority')->value]]] : NULL,
      'deadline' => $this->tasks->deadline($task) ?: NULL,
      'subtasks' => $subtasks ? ['#type' => 'html_tag', '#tag' => 'span', '#value' => '☑ ' . $done . '/' . count($subtasks), '#attributes' => ['class' => ['dv-card__subtasks'], 'title' => $this->t('Subtasks done')]] : NULL,
    ]);
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dv-card'],
        'data-dv-task' => $task->id(),
        'data-dv-draggable' => $task->access('update') ? 'true' : 'false',
      ],
      'top' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-card__top']],
        'type' => $this->tasks->type($task),
        'code' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => (string) $task->get('code')->value, '#attributes' => ['class' => ['dv-card__code']]],
      ],
      'title' => $task->toLink($task->get('name')->value)->toRenderable() + ['#attributes' => ['class' => ['dv-card__title']]],
      'parent' => $task->get('parent')->entity ? ['#type' => 'html_tag', '#tag' => 'div', '#value' => '↑ ' . $task->get('parent')->entity->label(), '#attributes' => ['class' => ['dv-card__parent']]] : [],
      'bottom' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dv-card__bottom']],
        'meta' => ['#type' => 'container', '#attributes' => ['class' => ['dv-card__meta']]] + $meta,
        'assignees' => $this->tasks->assignees($task),
      ],
    ];
  }

  protected function page(): array {
    return [
      '#attached' => ['library' => ['divengine_project/project']],
      '#cache' => ['tags' => ['project_list', 'project_task_list', 'config:project_stage_list'], 'contexts' => ['user']],
    ];
  }

  protected function section($title, int $count, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title, 'count' => $count ? (string) $count : ''],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

  protected function badge(string $label, string $tone): array {
    return ['#type' => 'component', '#component' => 'divengine_core:badge', '#props' => ['label' => $label, 'tone' => $tone]];
  }

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}
