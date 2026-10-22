<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_project\Kernel;

use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Two private projects: each one's people see it, its subprojects and its
 * tasks, and nothing of the other, through entity access and queries.
 */
#[RunTestsInSeparateProcesses]
class ProjectAccessTest extends ProjectKernelTestBase {

  public function testPrivateProjectsAndTheirSubprojects(): void {
    [$alice, $bob, $carol] = [$this->worker('alice'), $this->worker('bob'), $this->worker('carol')];
    $alpha = $this->project('ALPHA', $alice, ['members' => [$carol->id()]]);
    $alpha_sub = $this->project('ALPHASUB', $alice, ['parent' => $alpha->id()]);
    $beta = $this->project('BETA', $bob);
    $alpha_task = $this->task($alpha_sub, 'Alpha task');
    $beta_task = $this->task($beta, 'Beta task');

    // The owner and a member of Alpha see Alpha, its subproject and their
    // tasks; the owner of Beta sees only Beta.
    foreach ([$alice, $carol] as $user) {
      $this->assertSees($user, [$alpha, $alpha_sub, $alpha_task], [$beta, $beta_task]);
    }
    $this->assertSees($bob, [$beta, $beta_task], [$alpha, $alpha_sub, $alpha_task]);
    $this->assertSees($this->worker('dave'), [], [$alpha, $alpha_sub, $alpha_task, $beta, $beta_task]);

    // A member who leaves the project loses its subprojects and tasks too.
    $alpha->set('members', [])->save();
    $this->nextRequest();
    $this->assertSees($carol, [], [$alpha, $alpha_sub, $alpha_task]);

    // Moving a subproject under another project changes who sees it.
    $alpha_sub->set('parent', $beta->id())->save();
    $this->nextRequest();
    $this->assertSees($bob, [$alpha_sub, $alpha_task], []);
    $this->assertSees($alice, [$alpha_sub], []);
    $this->assertSees($alice, [], [$beta, $beta_task]);
  }

  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $visible
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $hidden
   */
  protected function assertSees(UserInterface $user, array $visible, array $hidden): void {
    $this->setCurrentUser($user);
    $etm = $this->container->get('entity_type.manager');
    $listed = [];
    foreach (['project', 'project_task'] as $type) {
      $etm->getAccessControlHandler($type)->resetCache();
      foreach ($etm->getStorage($type)->getQuery()->accessCheck(TRUE)->execute() as $id) {
        $listed["$type:$id"] = TRUE;
      }
    }
    foreach ($visible as $entity) {
      $key = $entity->getEntityTypeId() . ':' . $entity->id();
      $this->assertTrue($entity->access('view', $user), $user->getAccountName() . " sees $key.");
      $this->assertArrayHasKey($key, $listed, $user->getAccountName() . " lists $key.");
    }
    foreach ($hidden as $entity) {
      $key = $entity->getEntityTypeId() . ':' . $entity->id();
      $this->assertFalse($entity->access('view', $user), $user->getAccountName() . " does not see $key.");
      $this->assertFalse($entity->access('update', $user), $user->getAccountName() . " cannot edit $key.");
      $this->assertArrayNotHasKey($key, $listed, $user->getAccountName() . " does not list $key.");
    }
  }

}
