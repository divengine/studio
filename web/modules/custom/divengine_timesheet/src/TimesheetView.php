<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_project\ProjectVisibility;
use Drupal\divengine_timesheet\Entity\Timesheet;

/**
 * The time sections of task and project pages, entry tables, and who sees
 * which entries.
 */
class TimesheetView {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimesheetStats $stats,
    protected Timer $timer,
    protected ProjectVisibility $visibility,
    protected FormBuilderInterface $formBuilder,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * "Time" on a task: allocated vs spent, the timer, log time, entries.
   */
  public function task(ProjectTask $task): array {
    $figures = $this->stats->task($task);
    $can_log = $this->entityTypeManager->getAccessControlHandler('timesheet')->createAccess() && $task->access('view');
    $content = [];
    $content['figures'] = $this->figures([
      [$this->t('Allocated'), $figures['allocated'] ? Timesheet::formatHours($figures['allocated']) : '—'],
      [$this->t('Spent'), Timesheet::formatHours($figures['spent'])],
      [$this->t('On subtasks'), Timesheet::formatHours($figures['subtasks'])],
      [$figures['remaining'] < 0 ? $this->t('Overtime') : $this->t('Remaining'), $figures['allocated'] ? Timesheet::formatHours(abs($figures['remaining'])) : '—', $figures['overtime'] ? 'danger' : NULL],
    ]);
    if ($figures['allocated'] > 0) {
      $content['progress'] = $this->bar($figures['progress'], $figures['overtime']);
    }

    $actions = NULL;
    if ($can_log) {
      $running = $this->timer->current($this->currentUser);
      if ($running && (int) $running['task'] === (int) $task->id()) {
        $content['timer'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('⏱ Timer running since @time (@elapsed so far).', ['@time' => $this->dateFormatter->format($running['start'], 'custom', 'H:i'), '@elapsed' => Timesheet::formatHours($this->timer->hours($running))]),
          '#attributes' => ['class' => ['dv-timer']],
        ];
        $actions = $this->button($this->t('Stop timer'), Url::fromRoute('divengine_timesheet.timer_stop', ['project_task' => $task->id()]), TRUE);
      }
      else {
        $actions = $this->button($this->t('Start timer'), Url::fromRoute('divengine_timesheet.timer_start', ['project_task' => $task->id()]));
        if ($running && ($other = $this->entityTypeManager->getStorage('project_task')->load($running['task']))) {
          $content['timer'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('⏱ Your timer is running on @task. Starting it here stops that one and logs its time first.', ['@task' => $other->label()]),
            '#attributes' => ['class' => ['dv-timer', 'dv-timer--elsewhere']],
          ];
        }
      }
      $hours = (float) \Drupal::request()->query->get('hours', 0);
      $content['log'] = $this->formBuilder->getForm('Drupal\divengine_timesheet\Form\LogTimeForm', $task, $hours > 0 ? $hours : NULL);
    }
    $content['entries'] = $this->table($this->entries(['task' => $task->id()]), FALSE);
    return $this->section($this->t('Time'), $content, $actions) + [
      '#attached' => ['library' => ['divengine_timesheet/timesheet']],
      // A running timer shows its elapsed time: keep it fresh.
      '#cache' => ['tags' => ['timesheet_list'], 'contexts' => ['user'], 'max-age' => $can_log && $this->timer->current($this->currentUser) ? 0 : -1],
    ];
  }

  /**
   * "Time" on a project: logged vs allocated, and who logged it.
   */
  public function project(Project $project): array {
    $figures = $this->stats->project($project);
    $content = [];
    $content['figures'] = $this->figures([
      [$this->t('Logged'), Timesheet::formatHours($figures['total'])],
      [$this->t('Allocated to tasks'), $figures['allocated'] ? Timesheet::formatHours($figures['allocated']) : '—'],
      [$this->t('People'), (string) count($figures['people'])],
    ]);
    if ($figures['allocated'] > 0) {
      $content['progress'] = $this->bar(round($figures['total'] * 100 / $figures['allocated'], 1), $figures['total'] > $figures['allocated']);
    }
    if ($this->supervises($project)) {
      $rows = [];
      $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($figures['people']));
      foreach ($figures['people'] as $uid => $hours) {
        $rows[] = [(string) ($users[$uid]?->getDisplayName() ?? $uid), Timesheet::formatHours($hours)];
      }
      if ($rows) {
        $content['people'] = ['#type' => 'table', '#header' => [$this->t('Person'), $this->t('Hours')], '#rows' => $rows];
      }
    }
    $actions = $this->button($this->t('Timesheets'), Url::fromRoute('divengine_timesheet.project', ['project' => $project->id()]));
    return $this->section($this->t('Time'), $content, $actions) + [
      '#attached' => ['library' => ['divengine_timesheet/timesheet']],
      '#cache' => ['tags' => ['timesheet_list']],
    ];
  }

  /**
   * Visible entries matching conditions, newest first.
   *
   * @return \Drupal\divengine_timesheet\Entity\Timesheet[]
   */
  public function entries(array $conditions, int $limit = 0): array {
    $storage = $this->entityTypeManager->getStorage('timesheet');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('date', 'DESC')->sort('id', 'DESC');
    foreach ($conditions as $field => $value) {
      if (is_array($value) && count($value) === 2 && $field === 'date') {
        $query->condition('date', $value, 'BETWEEN');
      }
      else {
        $query->condition($field, $value, is_array($value) ? 'IN' : '=');
      }
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    return $storage->loadMultiple($query->execute());
  }

  /**
   * A table of entries, with their total.
   */
  public function table(array $entries, bool $with_task = TRUE): array {
    $rows = [];
    $total = 0.0;
    foreach ($entries as $entry) {
      $total += $entry->getHours();
      $operations = [];
      if ($entry->access('update')) {
        $operations[] = Link::fromTextAndUrl($this->t('Edit'), $entry->toUrl('edit-form', ['query' => \Drupal::destination()->getAsArray()]))->toRenderable();
      }
      if ($entry->access('delete')) {
        $operations[] = Link::fromTextAndUrl($this->t('Delete'), $entry->toUrl('delete-form', ['query' => \Drupal::destination()->getAsArray()]))->toRenderable();
      }
      $row = [
        ['data' => ['#plain_text' => (string) $entry->get('date')->value]],
        ['data' => ['#plain_text' => (string) $entry->getOwner()?->getDisplayName()]],
      ];
      if ($with_task) {
        $task = $entry->get('task')->entity;
        $row[] = ['data' => $task ? $task->toLink()->toRenderable() : ['#plain_text' => (string) $entry->get('project')->entity?->label()]];
      }
      $description = (string) $entry->get('description')->value;
      $row[] = ['data' => ['#plain_text' => ($description === '/' ? '—' : $description) . ($entry->get('adjustment')->value ? ' ' . $this->t('(adjustment)') : '')]];
      $row[] = ['data' => ['#plain_text' => Timesheet::formatHours($entry->getHours())], 'class' => ['dv-hours']];
      $row[] = ['data' => ['#type' => 'container', '#attributes' => ['class' => ['dv-row-links']], 'links' => $operations]];
      $rows[] = $row;
    }
    $header = [$this->t('Date'), $this->t('Person')];
    if ($with_task) {
      $header[] = $this->t('Task');
    }
    $header = array_merge($header, [$this->t('Description'), ['data' => $this->t('Hours'), 'class' => ['dv-hours']], '']);
    if ($rows) {
      $rows[] = ['data' => array_merge([['data' => ['#markup' => '<strong>' . $this->t('Total') . '</strong>'], 'colspan' => count($header) - 2]], [['data' => ['#markup' => '<strong>' . Timesheet::formatHours($total) . '</strong>'], 'class' => ['dv-hours']], '']), 'class' => ['dv-total-row']];
    }
    return ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No time logged yet.'), '#attributes' => ['class' => ['dv-timesheet-table']]];
  }

  /**
   * Restricts a timesheet query: own entries plus supervised projects.
   */
  public function alterQuery(SelectInterface $query): void {
    $account = $query->getMetaData('account') ?: $this->currentUser;
    if ($account->hasPermission('administer projects')) {
      return;
    }
    $alias = NULL;
    foreach ($query->getTables() as $table_alias => $info) {
      if (($info['table'] ?? NULL) === 'timesheet') {
        $alias = $table_alias;
        break;
      }
    }
    if ($alias === NULL) {
      return;
    }
    $visible = $this->visibility->visibleIds($account);
    if (!$visible) {
      $query->alwaysFalse();
      return;
    }
    $query->condition("$alias.project", $visible, 'IN');
    if ($account->hasPermission('manage timesheets')) {
      return;
    }
    $own = $query->orConditionGroup()->condition("$alias.uid", (int) $account->id());
    if ($managed = $this->visibility->managedIds($account)) {
      $own->condition("$alias.project", $managed, 'IN');
    }
    $query->condition($own);
  }

  /**
   * Whether the current user sees everyone's entries on a project.
   */
  public function supervises(Project $project): bool {
    return $this->currentUser->hasPermission('manage timesheets') || $this->currentUser->hasPermission('administer projects')
      || in_array((string) $project->id(), $this->visibility->managedIds($this->currentUser), TRUE);
  }

  protected function figures(array $figures): array {
    $build = ['#type' => 'container', '#attributes' => ['class' => ['dv-time-figures']]];
    foreach ($figures as $i => $figure) {
      [$label, $value] = $figure;
      $build[$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => array_filter(['dv-time-figure', !empty($figure[2]) ? 'dv-time-figure--' . $figure[2] : NULL])],
        'value' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $value],
        'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $label],
      ];
    }
    return $build;
  }

  protected function bar(float $percent, bool $overtime): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => array_filter(['dv-time-bar', $overtime ? 'dv-time-bar--over' : NULL])],
      'track' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['dv-time-bar__track']],
        'fill' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['dv-time-bar__fill'], 'style' => 'width:' . min(100, $percent) . '%']],
      ],
      'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('@p% of the allocated time', ['@p' => $percent]), '#attributes' => ['class' => ['dv-time-bar__label']]],
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

  protected function button($label, Url $url, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($label, $url)->toRenderable() + ['#attributes' => ['class' => array_filter(['button', 'button--small', $primary ? 'button--primary' : NULL])]];
  }

}
