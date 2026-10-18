<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\divengine_process\Entity\Development;
use Drupal\divengine_process\Entity\DevelopmentCase;
use Drupal\divengine_process\Entity\DevelopmentPhase;
use Drupal\divengine_process\Entity\Iteration;
use Drupal\divengine_process\Entity\Milestone;
use Drupal\divengine_process\Entity\RoleAssignment;
use Drupal\divengine_process\Lifecycle\TransitionException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Developments: structure, case snapshot, lifecycles and visibility.
 */
#[RunTestsInSeparateProcesses]
class DevelopmentTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

  protected UserInterface $creator;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('artifact');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('trace_link');
    $this->installSchema('divengine_process', ['divengine_process_baseline_item']);
    foreach (['development', 'development_phase', 'iteration', 'milestone', 'role_assignment', 'process_activity', 'change_request', 'baseline'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['divengine_artifact', 'divengine_traceability', 'divengine_process']);
    // Take uid 1, which bypasses permission checks.
    $this->createUser();
    $this->creator = $this->createUser(['use unified process']);
    $this->setCurrentUser($this->creator);
  }

  protected function createDevelopment(string $name = 'Billing'): Development {
    $development = Development::create(['name' => $name]);
    $development->save();
    return Development::load($development->id());
  }

  protected function lifecycle(): \Drupal\divengine_process\Lifecycle\LifecycleManager {
    return \Drupal::service('divengine_process.lifecycle');
  }

  /**
   * @return string[]
   */
  protected function violations(\Drupal\Core\Entity\FieldableEntityInterface $entity): array {
    return array_map(fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($entity->validate()));
  }

  public function testNewDevelopmentGetsPhasesMilestonesAndCase(): void {
    $development = $this->createDevelopment();
    $this->assertSame('DEV-0001', $development->getCode());
    $this->assertSame('[DEV-0001] Billing', $development->label());
    $this->assertSame((int) $this->creator->id(), (int) $development->get('responsible')->target_id);
    $this->assertSame('planned', $development->getState());
    $this->assertSame('DEV-0002', $this->createDevelopment('Second')->getCode());

    $process = \Drupal::service('divengine_process.development');
    $phases = array_values($process->phases($development));
    $this->assertSame(['inception', 'elaboration', 'construction', 'transition'], array_map(fn ($p) => $p->get('phase_type')->target_id, $phases));
    $this->assertSame($phases[0]->id(), $development->get('current_phase')->target_id);

    $milestones = array_values($process->children($development, 'milestone'));
    $this->assertSame(
      ['lifecycle_objectives', 'lifecycle_architecture', 'initial_operational_capability', 'product_release'],
      array_map(fn (Milestone $m) => $m->getMilestoneType(), $milestones),
    );
    $this->assertSame($phases[0]->id(), $milestones[0]->get('phase')->target_id);
    $this->assertSame('Lifecycle Objectives', $milestones[0]->label());

    $this->assertSame(0, $process->ensureStandardPhases($development), 'Adding missing phases is idempotent.');
    $duplicate = DevelopmentPhase::create(['development' => $development->id(), 'phase_type' => 'inception']);
    $this->assertContains('A Development has one phase of each type.', $this->violations($duplicate));

    $standard = DevelopmentCase::load('standard');
    $this->assertSame('standard', $development->getCase()->id());
    $this->assertSame($standard->getVersion(), $development->getCaseVersion());
    $this->assertSame($standard->getLines(), $development->getPolicy());
    $this->assertFalse($development->isCaseOutdated());
    $this->assertStringContainsString('Development Case Standard', $development->getRevisionLogMessage());
  }

  public function testCaseChangesNeverRetailorARunningDevelopment(): void {
    $development = $this->createDevelopment();
    $policy = $development->getPolicy();

    $case = DevelopmentCase::load('standard');
    $lines = $case->getLines();
    $lines[0]['level'] = 'optional';
    $lines[1]['level'] = 'optional';
    $case->setLines($lines)->save();

    $development = Development::load($development->id());
    $this->assertTrue($development->isCaseOutdated());
    $this->assertSame($policy, $development->getPolicy(), 'The snapshot is untouched.');

    $revision = $development->getRevisionId();
    \Drupal::service('divengine_process.development')->applyCase($development);
    $development = Development::load($development->id());
    $this->assertFalse($development->isCaseOutdated());
    $this->assertSame('optional', $development->getPolicy()[0]['level']);
    $this->assertGreaterThan($revision, $development->getRevisionId(), 'Applying the case is a new revision.');
  }

  public function testDevelopmentLifecycle(): void {
    $development = $this->createDevelopment();
    foreach (['activate', 'hold', 'activate', 'close'] as $transition) {
      $this->lifecycle()->apply($development, $transition);
    }
    $this->assertSame('closed', Development::load($development->id())->getState());

    try {
      $this->lifecycle()->apply($development, 'hold');
      $this->fail('A closed Development cannot be put on hold.');
    }
    catch (TransitionException $e) {
      $this->assertSame('[DEV-0001] Billing cannot move from Closed to On Hold.', $e->getMessage());
    }

    $development->set('state', 'active');
    $this->assertSame(['[DEV-0001] Billing can only change state through its transitions.'], $this->violations($development));
    $development->set('state', 'closed');

    $this->lifecycle()->apply($development, 'reset');
    $this->assertSame('planned', Development::load($development->id())->getState());
  }

  public function testIterationsStayInTheirDevelopment(): void {
    $development = $this->createDevelopment();
    $other = $this->createDevelopment('Other');
    $foreign_phase = $other->get('current_phase')->target_id;

    $iteration = Iteration::create([
      'development' => $development->id(),
      'phase' => $foreign_phase,
      'name' => 'I1',
      'date_start' => '2026-11-10',
      'date_end' => '2026-11-01',
    ]);
    $violations = $this->violations($iteration);
    $this->assertContains('Phase of I1 belongs to another Development.', $violations);
    $this->assertContains('The end date of I1 is before its start date.', $violations);

    $iteration->set('phase', $development->get('current_phase')->target_id)->set('date_end', '2026-11-30');
    $this->assertSame([], $this->violations($iteration));
    $iteration->save();

    $this->lifecycle()->apply($iteration, 'start');
    $this->assertSame($iteration->id(), Development::load($development->id())->get('current_iteration')->target_id);

    $development = Development::load($development->id());
    $development->set('current_phase', $foreign_phase);
    $this->assertContains('Current phase of [DEV-0001] Billing belongs to another Development.', $this->violations($development));
  }

  public function testMilestoneReachIsAdvisory(): void {
    $development = $this->createDevelopment();
    $milestone = current(\Drupal::service('divengine_process.development')->children($development, 'milestone'));
    $this->lifecycle()->apply($milestone, 'reach');
    $milestone = Milestone::load($milestone->id());
    $this->assertSame('reached', $milestone->getState());
    $this->assertSame(date('Y-m-d', \Drupal::time()->getRequestTime()), $milestone->get('date_reached')->value);

    $this->lifecycle()->apply($milestone, 'reset');
    $milestone = Milestone::load($milestone->id());
    $this->assertSame('planned', $milestone->getState());
    $this->assertNull($milestone->get('date_reached')->value);
  }

  public function testRoleAssignmentsAreUniqueAndGrantNoAccess(): void {
    $development = $this->createDevelopment();
    $player = $this->createUser(['use unified process']);
    RoleAssignment::create(['development' => $development->id(), 'process_role' => 'architect', 'user' => $player->id()])->save();
    $duplicate = RoleAssignment::create(['development' => $development->id(), 'process_role' => 'architect', 'user' => $player->id()]);
    $this->assertContains('This user already plays this role in the Development.', $this->violations($duplicate));
    $this->assertFalse($development->access('view', $player), 'Playing a role does not open the Development.');
  }

  public function testVisibility(): void {
    $development = $this->createDevelopment();
    $phase = DevelopmentPhase::load($development->get('current_phase')->target_id);
    $outsider = $this->createUser(['use unified process']);
    $member = $this->createUser(['use unified process']);
    $responsible = $this->createUser(['use unified process']);
    $manager = $this->createUser(['administer unified process']);
    $member_without_permission = $this->createUser([]);

    $this->assertTrue($development->access('view', $this->creator));
    $this->assertFalse($development->access('view', $outsider));
    $this->assertFalse($phase->access('view', $outsider), 'Records follow their Development.');
    $this->assertTrue($development->access('view', $manager));
    $this->assertTrue($phase->access('view', $manager));

    $development->set('members', [$member->id(), $member_without_permission->id()])->set('responsible', $responsible->id())->save();
    \Drupal::entityTypeManager()->getAccessControlHandler('development')->resetCache();
    \Drupal::entityTypeManager()->getAccessControlHandler('development_phase')->resetCache();
    $this->assertTrue($development->access('update', $member));
    $this->assertTrue($phase->access('update', $member));
    $this->assertTrue($development->access('view', $responsible));
    $this->assertFalse($development->access('view', $member_without_permission));

    $this->assertFalse($development->access('delete', $this->creator), 'Users cannot delete Developments.');
    $this->assertTrue($development->access('delete', $manager));
    $this->assertFalse($phase->access('delete', $member));
    $assignment = RoleAssignment::create(['development' => $development->id(), 'process_role' => 'tester', 'user' => $member->id()]);
    $assignment->save();
    $this->assertTrue($assignment->access('delete', $member), 'Lightweight records can be deleted by whoever edits the Development.');

    // Queries only return what the user sees.
    $this->setCurrentUser($outsider);
    $hidden = $this->createDevelopment('Outsider project');
    $count = fn (string $type) => (int) \Drupal::entityTypeManager()->getStorage($type)->getQuery()->accessCheck(TRUE)->count()->execute();
    $this->assertSame(1, $count('development'));
    $this->assertSame(4, $count('development_phase'));
    $this->setCurrentUser($member);
    $this->assertSame(1, $count('development'));
    $this->assertSame(4, $count('milestone'));
    $this->setCurrentUser($manager);
    $this->assertSame(2, $count('development'));
    $this->assertSame(8, $count('development_phase'));

    // Records are not added to a Development one cannot edit.
    $this->setCurrentUser($outsider);
    $iteration = Iteration::create(['development' => $development->id(), 'phase' => $phase->id(), 'name' => 'Sneaky']);
    $this->assertContains('You cannot add records to [DEV-0001] Billing.', $this->violations($iteration));
    $iteration->set('development', $hidden->id())->set('phase', $hidden->get('current_phase')->target_id);
    $this->assertSame([], $this->violations($iteration));
  }

}
