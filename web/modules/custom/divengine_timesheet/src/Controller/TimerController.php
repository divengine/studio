<?php

declare(strict_types=1);

namespace Drupal\divengine_timesheet\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_timesheet\Entity\Timesheet;
use Drupal\divengine_timesheet\Timer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Starts and stops the timer of a task.
 */
class TimerController extends ControllerBase {

  public function __construct(protected Timer $timer) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('divengine_timesheet.timer'));
  }

  /**
   * Starts the timer here; a timer running on another task is stopped and
   * its time logged on that task first.
   */
  public function start(ProjectTask $project_task): RedirectResponse {
    $stopped = $this->timer->start($this->currentUser(), (int) $project_task->id());
    if ($stopped) {
      $other = $this->entityTypeManager()->getStorage('project_task')->load($stopped['task']);
      $hours = $this->timer->hours($stopped);
      if ($other && $other->access('view')) {
        $this->entityTypeManager()->getStorage('timesheet')->create([
          'uid' => $this->currentUser()->id(),
          'task' => $other->id(),
          'project' => $other->get('project')->target_id,
          'hours' => $hours,
          'description' => (string) $this->t('Timer'),
        ])->save();
        $this->messenger()->addStatus($this->t('Logged %hours on %task from the timer you had running.', ['%hours' => Timesheet::formatHours($hours), '%task' => $other->label()]));
      }
    }
    $this->messenger()->addStatus($this->t('Timer started on %task.', ['%task' => $project_task->label()]));
    return new RedirectResponse($project_task->toUrl()->toString());
  }

  /**
   * Stops the timer and opens "Log time" with the hours filled in.
   */
  public function stop(ProjectTask $project_task): RedirectResponse {
    $hours = $this->timer->stop($this->currentUser());
    if ($hours > 0) {
      $this->messenger()->addStatus($this->t('Timer stopped: %hours. Describe what you did and log it.', ['%hours' => Timesheet::formatHours($hours)]));
    }
    return new RedirectResponse($project_task->toUrl('canonical', ['query' => ['hours' => $hours], 'fragment' => 'log-time'])->toString());
  }

  public function access(ProjectTask $project_task, AccountInterface $account): AccessResultInterface {
    return $project_task->access('view', $account, TRUE)
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['log time', 'manage timesheets'], 'OR'));
  }

}
