<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Url;
use Drupal\divengine_project\Entity\ProjectStage;
use Drupal\divengine_timesheet\Entity\Timesheet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "My timesheets": a week of the user's time, one row per task, one column
 * per day, like Odoo's timesheet grid.
 *
 * Rows are the tasks with time this week plus the open tasks assigned to
 * the user; "Add a line" brings any other visible task. Saving turns each
 * changed cell into entries: a new entry, an update of the cell's only
 * entry, its deletion when set to 0, or an adjustment entry for the
 * difference when the cell holds several entries.
 */
class TimesheetGridForm extends FormBase {

  /**
   * Monday of the week shown (Y-m-d).
   */
  protected string $monday;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected LockBackendInterface $lock,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('database'), $container->get('lock'));
  }

  public function getFormId(): string {
    return 'divengine_timesheet_grid';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $requested = (string) $this->getRequest()->query->get('week', '');
    $time = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) ? strtotime($requested) : \Drupal::time()->getRequestTime();
    $this->monday = date('Y-m-d', strtotime('monday this week', $time));
    $days = [];
    for ($i = 0; $i < 7; $i++) {
      $days[] = date('Y-m-d', strtotime($this->monday . " +$i days"));
    }
    $form_state->set('days', $days);

    $cells = $this->cells($days);
    $rows = $this->rows($cells, $form_state);
    $form_state->set('rows', $rows);

    $form['#attached']['library'][] = 'divengine_timesheet/grid';
    $form['#attributes']['class'][] = 'dv-grid-form';
    $week = fn (string $modifier) => Url::fromRoute('divengine_timesheet.grid', [], ['query' => ['week' => date('Y-m-d', strtotime($this->monday . ' ' . $modifier))]]);
    $form['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['divengine-process-actions', 'dv-grid-nav']],
      'prev' => Link::fromTextAndUrl('‹ ' . $this->t('Previous week'), $week('-7 days'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]],
      'today' => Link::fromTextAndUrl($this->t('This week'), Url::fromRoute('divengine_timesheet.grid'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]],
      'next' => Link::fromTextAndUrl($this->t('Next week') . ' ›', $week('+7 days'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]],
      'label' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $this->t('Week of @date', ['@date' => \Drupal::service('date.formatter')->format(strtotime($this->monday), 'custom', 'j F Y')]), '#attributes' => ['class' => ['dv-grid-nav__label']]],
    ];

    $today = date('Y-m-d', \Drupal::time()->getRequestTime());
    $header = [['data' => $this->t('Task'), 'class' => ['dv-grid__task']]];
    foreach ($days as $day) {
      $header[] = ['data' => ['#markup' => \Drupal::service('date.formatter')->format(strtotime($day), 'custom', 'D') . '<br><small>' . \Drupal::service('date.formatter')->format(strtotime($day), 'custom', 'j M') . '</small>'], 'class' => array_filter(['dv-grid__day', $day === $today ? 'dv-grid__day--today' : NULL])];
    }
    $header[] = ['data' => $this->t('Total'), 'class' => ['dv-grid__total']];
    $form['grid'] = [
      '#type' => 'table',
      '#header' => $header,
      '#attributes' => ['class' => ['dv-grid']],
      '#empty' => $this->t('Nothing to show this week. Use "Add a line" to log time on a task.'),
    ];
    $day_totals = array_fill_keys($days, 0.0);
    foreach ($rows as $key => $row) {
      $form['grid'][$key]['task'] = [
        '#wrapper_attributes' => ['class' => ['dv-grid__task']],
        'link' => $row['link'],
        'project' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => $row['project'], '#attributes' => ['class' => ['dv-grid__project']]],
      ];
      $row_total = 0.0;
      foreach ($days as $day) {
        $value = $cells[$key][$day]['hours'] ?? 0.0;
        $row_total += $value;
        $day_totals[$day] += $value;
        $form['grid'][$key][$day] = [
          '#type' => 'textfield',
          '#title' => $this->t('@task on @day', ['@task' => $row['label'], '@day' => $day]),
          '#title_display' => 'invisible',
          '#size' => 4,
          '#default_value' => $value ? Timesheet::formatHours($value) : '',
          '#placeholder' => '0:00',
          '#attributes' => ['class' => ['dv-grid__cell'], 'data-dv-day' => $day, 'inputmode' => 'decimal'],
          '#wrapper_attributes' => ['class' => array_filter(['dv-grid__day', $day === $today ? 'dv-grid__day--today' : NULL])],
        ];
      }
      $form['grid'][$key]['total'] = ['#markup' => '<span class="dv-grid__row-total">' . Timesheet::formatHours($row_total) . '</span>', '#wrapper_attributes' => ['class' => ['dv-grid__total']]];
    }
    if ($rows) {
      $form['grid']['_totals']['task'] = ['#markup' => '<strong>' . $this->t('Total') . '</strong>'];
      foreach ($days as $day) {
        $form['grid']['_totals'][$day] = ['#markup' => '<strong class="dv-grid__day-total" data-dv-day="' . $day . '">' . Timesheet::formatHours($day_totals[$day]) . '</strong>', '#wrapper_attributes' => ['class' => ['dv-grid__day']]];
      }
      $form['grid']['_totals']['total'] = ['#markup' => '<strong class="dv-grid__week-total">' . Timesheet::formatHours(array_sum($day_totals)) . '</strong>', '#wrapper_attributes' => ['class' => ['dv-grid__total']]];
    }

    $form['add'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dv-grid-add']],
      'task' => [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'project_task',
        '#title' => $this->t('Add a line'),
        '#placeholder' => $this->t('Search a task by code or title'),
      ],
      'add_line' => [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
        '#submit' => ['::addLine'],
        '#limit_validation_errors' => [['task']],
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save the week'), '#button_type' => 'primary'];
    return $form;
  }

  public function addLine(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->getValue('task');
    $task = $id ? $this->entityTypeManager->getStorage('project_task')->load($id) : NULL;
    if ($task && $task->access('view')) {
      $extra = $form_state->get('extra_tasks') ?? [];
      $extra[$task->id()] = $task->id();
      $form_state->set('extra_tasks', $extra);
    }
    $form_state->setRebuild();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getTriggeringElement()['#submit'][0] ?? NULL) {
      return;
    }
    foreach ($form_state->get('rows') ?? [] as $key => $row) {
      foreach ($form_state->get('days') as $day) {
        $raw = (string) $form_state->getValue(['grid', $key, $day]);
        $hours = $raw === '' ? 0.0 : Timesheet::parseHours($raw);
        if ($hours === NULL || $hours < 0 || $hours > 24) {
          $form_state->setErrorByName("grid][$key][$day", $this->t('@task on @day: enter hours as 1.5 or 1:30, between 0 and 24.', ['@task' => $row['label'], '@day' => $day]));
        }
      }
    }
  }

  /**
   * Saves the differences between the grid and the stored entries.
   *
   * The stored entries are read again under a lock per person, and the
   * changes are written in one transaction that ends before the lock is
   * released, so a submission sent twice (a retry, a double click, two tabs)
   * finds the first one's entries and records nothing more. The entries are
   * read before the transaction starts: on PostgreSQL, a query run by a
   * query alter inside a transaction fails.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $lock = 'divengine_timesheet_grid:' . (int) $this->currentUser()->id();
    $deadline = time() + 30;
    while (!$this->lock->acquire($lock)) {
      if (time() >= $deadline) {
        $this->messenger()->addError($this->t('Your timesheet is being saved by another request. Check it and try again.'));
        return;
      }
      $this->lock->wait($lock, 5);
    }
    try {
      $this->entityTypeManager->getStorage('timesheet')->resetCache();
      $cells = $this->cells($form_state->get('days'));
      $transaction = $this->database->startTransaction();
      try {
        $changed = $this->saveChanges($form_state, $cells);
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      unset($transaction);
    }
    finally {
      $this->lock->release($lock);
    }
    $this->messenger()->addStatus($changed ? $this->formatPlural($changed, 'Saved 1 change.', 'Saved @count changes.') : $this->t('Nothing changed.'));
  }

  /**
   * Writes the grid's changes and returns how many cells changed.
   */
  protected function saveChanges(FormStateInterface $form_state, array $cells): int {
    $storage = $this->entityTypeManager->getStorage('timesheet');
    $days = $form_state->get('days');
    $changed = 0;
    foreach ($form_state->get('rows') as $key => $row) {
      foreach ($days as $day) {
        $raw = (string) $form_state->getValue(['grid', $key, $day]);
        $new = $raw === '' ? 0.0 : (float) Timesheet::parseHours($raw);
        $current = $cells[$key][$day]['hours'] ?? 0.0;
        if (abs($new - $current) < 0.005) {
          continue;
        }
        $entries = $cells[$key][$day]['entries'] ?? [];
        if (count($entries) === 1) {
          $entry = reset($entries);
          if ($new == 0) {
            $entry->delete();
          }
          else {
            $entry->set('hours', $new)->save();
          }
        }
        else {
          $storage->create([
            'uid' => $this->currentUser()->id(),
            'date' => $day,
            'task' => $row['task'],
            'project' => $row['project_id'],
            'hours' => $entries ? $new - $current : $new,
            'adjustment' => (bool) $entries,
            'description' => $entries ? (string) $this->t('Timesheet adjustment') : '/',
          ])->save();
        }
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * The user's entries of the week, by row and day.
   *
   * @return array<string, array<string, array{hours: float, entries: \Drupal\divengine_timesheet\Entity\Timesheet[]}>>
   */
  protected function cells(array $days): array {
    $storage = $this->entityTypeManager->getStorage('timesheet');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $this->currentUser()->id())
      ->condition('date', [reset($days), end($days)], 'BETWEEN')
      ->execute();
    $cells = [];
    foreach ($storage->loadMultiple($ids) as $entry) {
      $key = $entry->get('task')->target_id ? 't' . $entry->get('task')->target_id : 'p' . $entry->get('project')->target_id;
      $day = (string) $entry->get('date')->value;
      $cells[$key][$day]['hours'] = ($cells[$key][$day]['hours'] ?? 0.0) + $entry->getHours();
      $cells[$key][$day]['entries'][$entry->id()] = $entry;
    }
    return $cells;
  }

  /**
   * The rows: tasks (and projects without task) with time this week, open
   * tasks assigned to the user and lines added on the form.
   */
  protected function rows(array $cells, FormStateInterface $form_state): array {
    $tasks = $this->entityTypeManager->getStorage('project_task');
    $task_ids = [];
    $project_ids = [];
    foreach (array_keys($cells) as $key) {
      $key[0] === 't' ? $task_ids[] = (int) substr($key, 1) : $project_ids[] = (int) substr($key, 1);
    }
    $open = array_keys(array_filter(ProjectStage::loadOrdered(), fn (ProjectStage $s) => !$s->isClosed()));
    if ($open) {
      $task_ids = array_merge($task_ids, array_values($tasks->getQuery()->accessCheck(TRUE)->condition('assignees', $this->currentUser()->id())->condition('stage', $open, 'IN')->execute()));
    }
    $task_ids = array_merge($task_ids, array_values($form_state->get('extra_tasks') ?? []));
    $rows = [];
    foreach ($tasks->loadMultiple(array_unique($task_ids)) as $task) {
      if (!$task->access('view')) {
        continue;
      }
      $rows['t' . $task->id()] = [
        'label' => $task->label(),
        'link' => $task->toLink()->toRenderable(),
        'project' => (string) $task->getProject()?->label(),
        'project_id' => $task->get('project')->target_id,
        'task' => $task->id(),
      ];
    }
    foreach ($this->entityTypeManager->getStorage('project')->loadMultiple($project_ids) as $project) {
      $rows['p' . $project->id()] = [
        'label' => $project->label(),
        'link' => $project->toLink()->toRenderable(),
        'project' => (string) $this->t('No task'),
        'project_id' => $project->id(),
        'task' => NULL,
      ];
    }
    uasort($rows, fn ($a, $b) => [$a['project'], $a['label']] <=> [$b['project'], $b['label']]);
    return $rows;
  }

}
