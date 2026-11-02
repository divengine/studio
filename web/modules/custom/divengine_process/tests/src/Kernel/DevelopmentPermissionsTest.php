<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_process\Entity\Baseline;
use Drupal\divengine_process\Entity\ChangeRequest;
use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\DevelopmentPhase;
use Drupal\divengine_process\Entity\Iteration;
use Drupal\divengine_process\Entity\Milestone;
use Drupal\divengine_process\Entity\ProcessActivity;
use Drupal\divengine_process\Entity\RoleAssignment;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Taking part in a Development is not managing it (#84).
 *
 * Members (and the creator, Responsible and Process Managers) see a
 * Development and add records to it. Only the creator, the Responsible and
 * Process Managers edit the Development (details, members, Responsible) and
 * its process structure. Creating a Development needs "create
 * developments"; changing its creator, Process Manager.
 */
#[RunTestsInSeparateProcesses]
class DevelopmentPermissionsTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  /**
   * The engineering_contributor role of the #72 pilot, process part.
   */
  const CONTRIBUTOR = ['access divengine', 'use unified process', 'view artifact', 'create requirement artifact', 'edit requirement artifact', 'use requirement_lifecycle transition draft'];

  protected UserInterface $creator;

  protected UserInterface $responsible;

  protected UserInterface $member;

  protected UserInterface $contributor;

  protected UserInterface $manager;

  protected UserInterface $outsider;

  protected Development $development;

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'artifact', 'content_moderation_state', 'trace_link', 'development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    $this->installConfig(['divengine_artifact', 'divengine_traceability', 'divengine_process']);
    // User 1 bypasses permission checks.
    $this->createUser();
    $this->creator = $this->createUser(['use unified process', 'create developments', 'create artifact']);
    $this->responsible = $this->createUser(['use unified process']);
    $this->member = $this->createUser(['use unified process', 'create artifact']);
    $this->contributor = $this->createUser(static::CONTRIBUTOR);
    $this->manager = $this->createUser(['administer unified process']);
    $this->outsider = $this->createUser(['use unified process', 'create developments']);
    $this->setCurrentUser($this->creator);
    $development = Development::create(['name' => 'Billing']);
    $development->save();
    $development->set('responsible', $this->responsible->id())->set('members', [$this->member->id(), $this->contributor->id()])->save();
    $this->development = Development::load($development->id());
  }

  /**
   * @return string[]
   */
  protected function violations(FieldableEntityInterface $entity, UserInterface $as): array {
    $this->setCurrentUser($as);
    return array_map(fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($entity->validate()));
  }

  public function testDevelopmentOperations(): void {
    $expected = [
      // view, contribute, update.
      'creator' => [TRUE, TRUE, TRUE],
      'responsible' => [TRUE, TRUE, TRUE],
      'member' => [TRUE, TRUE, FALSE],
      'contributor' => [TRUE, TRUE, FALSE],
      'manager' => [TRUE, TRUE, TRUE],
      'outsider' => [FALSE, FALSE, FALSE],
    ];
    foreach ($expected as $who => [$view, $contribute, $update]) {
      $account = $this->$who;
      $this->assertSame($view, $this->development->access('view', $account), "$who view");
      $this->assertSame($contribute, $this->development->access('contribute', $account), "$who contribute");
      $this->assertSame($update, $this->development->access('update', $account), "$who update");
      $this->assertSame($who === 'manager', $this->development->access('delete', $account), "$who delete");
    }

    // Creating Developments is its own permission.
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('development');
    $this->assertTrue($handler->createAccess(NULL, $this->creator));
    $this->assertTrue($handler->createAccess(NULL, $this->manager));
    $this->assertFalse($handler->createAccess(NULL, $this->member), 'Taking part does not create Developments.');
    $this->assertFalse($handler->createAccess(NULL, $this->contributor));

    // Members, Responsible: editable by those who manage; the creator only
    // by Process Managers.
    $this->assertFalse($this->development->get('uid')->access('edit', $this->creator), 'The creator cannot hand the Development over.');
    $this->assertTrue($this->development->get('uid')->access('edit', $this->manager));
    $this->assertTrue($this->development->get('members')->access('edit', $this->responsible));
  }

  public function testStructureIsManagedParticipationIsOpen(): void {
    $id = $this->development->id();
    $phase = DevelopmentPhase::load($this->development->get('current_phase')->target_id);
    $milestone = Milestone::load(current(\Drupal::entityQuery('milestone')->accessCheck(FALSE)->condition('development', $id)->range(0, 1)->execute()));

    // Process structure: only those who manage add or edit it.
    $structure = [
      'iteration' => fn () => Iteration::create(['development' => $id, 'phase' => $phase->id(), 'name' => 'I1']),
      'role assignment' => fn () => RoleAssignment::create(['development' => $id, 'process_role' => 'tester', 'user' => $this->member->id()]),
    ];
    foreach ($structure as $what => $make) {
      foreach (['member', 'contributor'] as $who) {
        $this->assertContains('You cannot add records to [DEV-0001] Billing.', $this->violations($make(), $this->$who), "$who cannot add a $what");
      }
      foreach (['creator', 'responsible', 'manager'] as $who) {
        $this->assertNotContains('You cannot add records to [DEV-0001] Billing.', $this->violations($make(), $this->$who), "$who adds a $what");
      }
    }
    foreach (['phase' => $phase, 'milestone' => $milestone] as $what => $record) {
      $this->assertFalse($record->access('update', $this->member), "member cannot edit the $what");
      $this->assertTrue($record->access('update', $this->responsible), "responsible edits the $what");
    }
    $iteration = Iteration::create(['development' => $id, 'phase' => $phase->id(), 'name' => 'I2']);
    $iteration->save();
    $this->assertFalse($iteration->access('update', $this->member));
    $assignment = RoleAssignment::create(['development' => $id, 'process_role' => 'analyst', 'user' => $this->member->id()]);
    $assignment->save();
    $this->assertFalse($assignment->access('delete', $this->member), 'Members do not remove role assignments.');
    $this->assertTrue($assignment->access('delete', $this->responsible));

    // Baselines are structure too.
    $this->setCurrentUser($this->responsible);
    $baseline = \Drupal::service('divengine_process.baselines')->take($this->development, 'B1');
    $this->assertFalse(Baseline::load($baseline->id())->access('update', $this->member));
    $this->assertTrue(Baseline::load($baseline->id())->access('update', $this->responsible));

    // Participation: members add and edit their records.
    $participation = [
      'change request' => fn () => ChangeRequest::create(['development' => $id, 'name' => 'Change', 'description' => 'A change.']),
      'process activity' => fn () => ProcessActivity::create(['development' => $id, 'name' => 'Write the vision']),
      'requirement' => fn () => Artifact::create(['type' => 'requirement', 'title' => 'R', 'development' => $id, 'field_statement' => 'The system shall.']),
    ];
    foreach ($participation as $what => $make) {
      foreach (['member', 'contributor'] as $who) {
        $this->assertNotContains('You cannot add records to [DEV-0001] Billing.', $this->violations($make(), $this->$who), "$who adds a $what");
      }
      $this->assertContains('You cannot add records to [DEV-0001] Billing.', $this->violations($make(), $this->outsider), "outsider cannot add a $what");
    }
    $request = ChangeRequest::create(['development' => $id, 'name' => 'Change', 'description' => 'A change.']);
    $request->save();
    $this->assertTrue($request->access('update', $this->member));
    $this->assertFalse($request->access('update', $this->outsider));
  }

}
