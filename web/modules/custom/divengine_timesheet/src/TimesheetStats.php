<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet;

use Drupal\Core\Database\Connection;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;

/**
 * Time figures of tasks and projects, as Odoo shows them.
 *
 * - spent: hours logged on the task itself;
 * - subtasks: hours logged on its subtasks, at any depth;
 * - total: spent + subtasks;
 * - allocated: the task's allocated time;
 * - remaining: allocated - total (negative is overtime);
 * - progress: total / allocated, in percent.
 *
 * Totals count every entry: everyone who sees a task sees how much time it
 * took, not who logged each line.
 */
class TimesheetStats {

  public function __construct(protected Connection $database) {}

  /**
   * @return array{spent: float, subtasks: float, total: float, allocated: float, remaining: float, progress: float, overtime: bool}
   */
  public function task(ProjectTask $task): array {
    $descendants = $this->descendants((int) $task->id());
    $spent = $this->sum(['task' => [(int) $task->id()]]);
    $subtasks = $descendants ? $this->sum(['task' => $descendants]) : 0.0;
    $total = $spent + $subtasks;
    $allocated = (float) $task->get('estimate')->value;
    return [
      'spent' => $spent,
      'subtasks' => $subtasks,
      'total' => $total,
      'allocated' => $allocated,
      'remaining' => $allocated - $total,
      'progress' => $allocated > 0 ? round($total * 100 / $allocated, 1) : 0.0,
      'overtime' => $allocated > 0 && $total > $allocated,
    ];
  }

  /**
   * Hours logged on a project and the hours allocated to its tasks.
   *
   * @return array{total: float, allocated: float, people: array<int, float>}
   */
  public function project(Project $project): array {
    $people = $this->database->select('timesheet', 't')
      ->condition('t.project', $project->id());
    $people->addField('t', 'uid');
    $people->addExpression('SUM(t.hours)', 'hours');
    $people->groupBy('t.uid');
    $by_person = array_map('floatval', $people->execute()->fetchAllKeyed());
    arsort($by_person);
    $allocated = $this->database->select('project_task', 'pt')->condition('pt.project', $project->id());
    $allocated->addExpression('SUM(pt.estimate)', 'allocated');
    return [
      'total' => array_sum($by_person),
      'allocated' => (float) $allocated->execute()->fetchField(),
      'people' => $by_person,
    ];
  }

  /**
   * Sum of hours matching conditions (field => values).
   */
  public function sum(array $conditions): float {
    $query = $this->database->select('timesheet', 't');
    foreach ($conditions as $field => $values) {
      $query->condition('t.' . $field, $values, 'IN');
    }
    $query->addExpression('SUM(t.hours)', 'hours');
    return (float) $query->execute()->fetchField();
  }

  /**
   * IDs of every subtask below a task.
   *
   * @return int[]
   */
  protected function descendants(int $task_id): array {
    $ids = [];
    $frontier = [$task_id];
    while ($frontier) {
      $children = array_map('intval', $this->database->select('project_task', 'pt')->fields('pt', ['id'])->condition('pt.parent', $frontier, 'IN')->execute()->fetchCol());
      $frontier = array_values(array_diff($children, $ids, [$task_id]));
      $ids = array_merge($ids, $frontier);
    }
    return $ids;
  }

}
