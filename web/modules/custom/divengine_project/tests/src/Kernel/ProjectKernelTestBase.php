<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_project\Kernel;

use Drupal\divengine_project\Entity\Project;
use Drupal\divengine_project\Entity\ProjectTask;
use Drupal\divengine_project\ProjectVisibility;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Projects and tasks with users who work on them.
 */
abstract class ProjectKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'comment', 'taxonomy', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_project'];

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'comment', 'project', 'project_task'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['comment', 'divengine_project']);
    // User 1 bypasses access; the fixtures start at user 2.
    User::create(['name' => 'admin'])->save();
    Role::create(['id' => 'worker', 'label' => 'Worker'])->grantPermission('use projects')->save();
  }

  protected function worker(string $name): UserInterface {
    $user = User::create(['name' => $name, 'roles' => ['worker'], 'status' => 1]);
    $user->save();
    return $user;
  }

  protected function project(string $key, UserInterface $owner, array $values = []): Project {
    $project = Project::create($values + ['name' => "Project $key", 'project_key' => $key, 'uid' => $owner->id()]);
    $project->save();
    return $project;
  }

  protected function task(Project $project, string $name, array $values = []): ProjectTask {
    $task = ProjectTask::create($values + ['name' => $name, 'project' => $project->id()]);
    $task->save();
    return $task;
  }

  /**
   * Forgets what this request knows about visibility, as the next one would.
   */
  protected function nextRequest(): void {
    $this->container->set('divengine_project.visibility', new ProjectVisibility($this->container->get('database'), $this->container->get('entity_type.manager')));
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_type.manager')->getStorage('project')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('project_task')->resetCache();
  }

}
