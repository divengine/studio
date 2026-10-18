<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_timesheet\Entity\Timesheet;
use Drupal\divengine_timesheet\TimesheetView;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Timesheet lists: the Timesheets tab of a project and the report.
 */
class TimesheetPagesController extends ControllerBase {

  protected TimesheetView $view;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->view = $container->get('divengine_timesheet.view');
    return $instance;
  }

  public function projectTitle(Project $project): string {
    return (string) $this->t('@project: timesheets', ['@project' => $project->label()]);
  }

  /**
   * Time on a project, by task, and the entries the user may see.
   */
  public function project(Project $project): array {
    $entries = $this->view->entries(['project' => $project->id()]);
    $by_task = [];
    foreach ($entries as $entry) {
      $key = (string) ($entry->get('task')->target_id ?? '');
      $by_task[$key] = ($by_task[$key] ?? 0.0) + $entry->getHours();
    }
    arsort($by_task);
    $tasks = $this->entityTypeManager->getStorage('project_task')->loadMultiple(array_filter(array_keys($by_task)));
    $rows = [];
    foreach ($by_task as $task_id => $hours) {
      $task = $tasks[$task_id] ?? NULL;
      $rows[] = [
        ['data' => $task ? $task->toLink()->toRenderable() : ['#plain_text' => (string) $this->t('No task')]],
        ['data' => ['#plain_text' => $task && $task->get('estimate')->value ? Timesheet::formatHours((float) $task->get('estimate')->value) : '—']],
        ['data' => ['#plain_text' => Timesheet::formatHours($hours)], 'class' => ['dv-hours']],
      ];
    }
    $add = $this->entityTypeManager->getAccessControlHandler('timesheet')->createAccess()
      ? Link::fromTextAndUrl($this->t('Log time'), Url::fromRoute('entity.timesheet.add_form', [], ['query' => ['project' => $project->id()]]))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small', 'button--primary']]]
      : NULL;
    return [
      '#attached' => ['library' => ['divengine_timesheet/timesheet']],
      '#cache' => ['tags' => ['timesheet_list', 'project_task_list'], 'contexts' => ['user']],
      'intro' => ['#markup' => '<p class="dv-intro">' . ($this->view->supervises($project)
        ? $this->t('Everyone\'s time on this project.')
        : $this->t('Your time on this project. Project managers see everyone\'s.')) . '</p>'],
      'by_task' => $this->section($this->t('By task'), ['#type' => 'table', '#header' => [$this->t('Task'), $this->t('Allocated'), ['data' => $this->t('Logged'), 'class' => ['dv-hours']]], '#rows' => $rows, '#empty' => $this->t('No time logged yet.')], $add),
      'entries' => $this->section($this->t('Entries'), $this->view->table($entries)),
    ];
  }

  /**
   * Time over a period, grouped by person, project or task.
   */
  public function report(Request $request): array {
    $q = $request->query;
    $from = $q->get('from') ?: date('Y-m-01', \Drupal::time()->getRequestTime());
    $to = $q->get('to') ?: date('Y-m-t', \Drupal::time()->getRequestTime());
    $conditions = ['date' => [$from, $to]];
    if ($project = $q->get('project')) {
      $conditions['project'] = $project;
    }
    if ($person = $q->get('person')) {
      $conditions['uid'] = $person;
    }
    $group = in_array($q->get('group'), ['person', 'project', 'task'], TRUE) ? $q->get('group') : 'person';
    $entries = $this->view->entries($conditions);

    $groups = [];
    foreach ($entries as $entry) {
      [$key, $label] = match ($group) {
        'project' => ['p' . $entry->get('project')->target_id, $entry->get('project')->entity?->label()],
        'task' => ['t' . ($entry->get('task')->target_id ?? 0), $entry->get('task')->entity?->label() ?? (string) $this->t('No task') . ' · ' . $entry->get('project')->entity?->label()],
        default => ['u' . $entry->getOwnerId(), $entry->getOwner()?->getDisplayName()],
      };
      $groups[$key]['label'] = (string) $label;
      $groups[$key]['hours'] = ($groups[$key]['hours'] ?? 0.0) + $entry->getHours();
      $groups[$key]['days'][(string) $entry->get('date')->value] = TRUE;
    }
    uasort($groups, fn ($a, $b) => $b['hours'] <=> $a['hours']);
    $total = array_sum(array_column($groups, 'hours'));
    $rows = [];
    foreach ($groups as $item) {
      $share = $total ? round($item['hours'] * 100 / $total) : 0;
      $rows[] = [
        $item['label'],
        (string) count($item['days']),
        ['data' => [
          'bar' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['dv-share'], 'style' => '--dv-share:' . $share . '%']],
          'value' => ['#markup' => Timesheet::formatHours($item['hours'])],
        ], 'class' => ['dv-hours']],
      ];
    }
    return [
      '#attached' => ['library' => ['divengine_timesheet/timesheet']],
      '#cache' => ['tags' => ['timesheet_list'], 'contexts' => ['user', 'url.query_args']],
      'filters' => $this->formBuilder()->getForm('Drupal\divengine_timesheet\Form\ReportFilterForm', $from, $to),
      'summary' => $this->section($this->t('Total: @hours', ['@hours' => Timesheet::formatHours($total)]), [
        '#type' => 'table',
        '#header' => [match ($group) {
          'project' => $this->t('Project'),
          'task' => $this->t('Task'),
          default => $this->t('Person'),
        }, $this->t('Days'), ['data' => $this->t('Hours'), 'class' => ['dv-hours']]],
        '#rows' => $rows,
        '#empty' => $this->t('No time logged in this period.'),
      ]),
      'entries' => $this->section($this->t('Entries'), $this->view->table($entries)),
    ];
  }

  protected function section($title, array $content, ?array $actions = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'divengine_core:section',
      '#props' => ['title' => (string) $title],
      '#slots' => array_filter(['content' => $content, 'actions' => $actions]),
    ];
  }

}
