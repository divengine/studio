<?php

declare(strict_types=1);

namespace Drupal\divengine_project\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectStage;
use Drupal\divengine_project\Entity\ProjectTask;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Moves tasks between stages: one click on a task page, or drag and drop on
 * the board (which also stores the order of the column).
 */
class TaskMoveController extends ControllerBase {

  public function move(ProjectTask $project_task, ProjectStage $stage): RedirectResponse {
    if ($error = $this->setStage($project_task, $stage)) {
      $this->messenger()->addError($error);
    }
    else {
      $this->messenger()->addStatus($this->t('%task moved to %stage.', ['%task' => $project_task->label(), '%stage' => $stage->label()]));
    }
    return new RedirectResponse($project_task->toUrl()->toString());
  }

  public function moveAccess(ProjectTask $project_task, AccountInterface $account): AccessResultInterface {
    return $project_task->access('update', $account, TRUE);
  }

  /**
   * Board drop: {"task": 12, "stage": "in_progress", "order": [12, 7, 9]}.
   */
  public function boardMove(Project $project, Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $storage = $this->entityTypeManager()->getStorage('project_task');
    $task = $storage->load($data['task'] ?? 0);
    $stage = ProjectStage::load((string) ($data['stage'] ?? ''));
    if (!$task instanceof ProjectTask || (string) $task->get('project')->target_id !== (string) $project->id() || !$stage) {
      return new JsonResponse(['message' => (string) $this->t('The task or the stage no longer exists. Reload the board.')], 400);
    }
    if (!$task->access('update')) {
      return new JsonResponse(['message' => (string) $this->t('You cannot move %task.', ['%task' => $task->label()])], 403);
    }
    if ((string) $task->get('stage')->target_id !== $stage->id() && ($error = $this->setStage($task, $stage))) {
      return new JsonResponse(['message' => $error], 422);
    }
    // Store the order of the column for the tasks the user may change.
    foreach (array_values(array_map('intval', (array) ($data['order'] ?? []))) as $weight => $id) {
      $sibling = $storage->load($id);
      if ($sibling instanceof ProjectTask && (string) $sibling->get('project')->target_id === (string) $project->id() && (int) $sibling->get('weight')->value !== $weight && $sibling->access('update')) {
        $sibling->set('weight', $weight)->setNewRevision(FALSE);
        $sibling->save();
      }
    }
    return new JsonResponse(['message' => (string) $this->t('%task moved to %stage.', ['%task' => $task->label(), '%stage' => $stage->label()])]);
  }

  public function boardAccess(Project $project, AccountInterface $account): AccessResultInterface {
    return $project->access('view', $account, TRUE)
      ->andIf(AccessResult::allowedIfHasPermissions($account, ['use projects', 'manage projects', 'administer projects'], 'OR'));
  }

  /**
   * Changes the stage as a new revision; returns the refusal, if any.
   */
  protected function setStage(ProjectTask $task, ProjectStage $stage): ?string {
    $previous = $task->getStage();
    $task->set('stage', $stage->id());
    $violations = $task->validate()->getByField('stage');
    if ($violations->count()) {
      return (string) $violations[0]->getMessage();
    }
    $task->setNewRevision(TRUE);
    $task->setRevisionUserId((int) $this->currentUser()->id());
    $task->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $task->setRevisionLogMessage((string) $this->t('Moved from @from to @to.', ['@from' => $previous?->label() ?? '-', '@to' => $stage->label()]));
    $task->save();
    return NULL;
  }

}
