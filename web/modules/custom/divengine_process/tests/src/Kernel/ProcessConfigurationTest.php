<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Kernel;

use Drupal\divengine_process\Entity\DevelopmentCase;
use Drupal\divengine_process\Entity\ProcessDiscipline;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Process configuration: seed data, Development Case versioning and access.
 */
#[RunTestsInSeparateProcesses]
class ProcessConfigurationTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'datetime', 'link', 'options', 'text', 'field', 'filter', 'views', 'workflows', 'content_moderation', 'divengine_core', 'divengine_artifact', 'divengine_traceability', 'divengine_process'];

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
  }

  public function testSeedData(): void {
    $count = fn (string $type): int => count(\Drupal::entityTypeManager()->getStorage($type)->loadMultiple());
    $this->assertSame(4, $count('process_phase_type'));
    $this->assertSame(9, $count('process_discipline'));
    $this->assertSame(9, $count('process_role'));
    $this->assertSame(17, $count('work_product_definition'));
    $this->assertSame(15, $count('activity_definition'));
    $this->assertSame(2, $count('development_case'));

    $phases = \Drupal::entityTypeManager()->getStorage('process_phase_type')->loadMultiple();
    uasort($phases, [\Drupal\divengine_process\Entity\ProcessPhaseType::class, 'sort']);
    $this->assertSame(['inception', 'elaboration', 'construction', 'transition'], array_keys($phases));
    $this->assertSame('lifecycle_objectives', $phases['inception']->getMilestoneType());
    $this->assertSame('product_release', $phases['transition']->getMilestoneType());

    $standard = DevelopmentCase::load('standard');
    $brownfield = DevelopmentCase::load('brownfield');
    $this->assertCount(24, $standard->getLines());
    $this->assertCount(36, $brownfield->getLines());
    $this->assertSame([], $standard->validateLines());
    $this->assertSame([], $brownfield->validateLines());
    $this->assertSame('standard', DevelopmentCase::loadDefault()->id());

    $required = fn (DevelopmentCase $case): array => array_column(array_filter(
      $case->getLines(),
      fn (array $l): bool => $l['element_type'] === 'discipline' && $l['level'] === 'required',
    ), 'target');
    $this->assertEqualsCanonicalizing(['requirements', 'analysis_design', 'implementation', 'test'], $required($standard));
    $this->assertEqualsCanonicalizing(
      ['business_modeling', 'requirements', 'analysis_design', 'implementation', 'test', 'configuration_change'],
      $required($brownfield),
    );

    $definition = \Drupal::entityTypeManager()->getStorage('activity_definition')->load('implement_integrate');
    $this->assertSame('Implement and Integrate', $definition->label());
    $this->assertSame(['design_model'], $definition->getInputDefinitions());
    $this->assertSame(['build'], $definition->getOutputDefinitions());
    $this->assertContains('divengine_process.discipline.analysis_design', \Drupal::entityTypeManager()->getStorage('process_role')->load('architect')->getDependencies()['config']);
  }

  public function testCaseVersionIncrementsOncePerSaveThatChangesLines(): void {
    $case = DevelopmentCase::load('standard');
    $version = $case->getVersion();

    $case->set('label', 'Standard (renamed)')->save();
    $this->assertSame($version, DevelopmentCase::load('standard')->getVersion(), 'Renaming does not change the version.');

    $lines = $case->getLines();
    $lines[0]['level'] = 'recommended';
    $lines[1]['note'] = 'Edited';
    $lines[] = ['element_type' => 'discipline', 'target' => 'deployment', 'level' => 'optional'];
    $case->setLines($lines)->save();
    $this->assertSame($version + 1, DevelopmentCase::load('standard')->getVersion(), 'Several line changes in one save are one version.');

    $case->save();
    $this->assertSame($version + 1, DevelopmentCase::load('standard')->getVersion(), 'Saving unchanged lines keeps the version.');
  }

  public function testSingleDefaultCase(): void {
    DevelopmentCase::load('brownfield')->set('is_default', TRUE)->save();
    $this->assertFalse(DevelopmentCase::load('standard')->isDefault());
    $this->assertSame('brownfield', DevelopmentCase::loadDefault()->id());

    DevelopmentCase::load('brownfield')->disable()->save();
    $this->assertNull(DevelopmentCase::loadDefault(), 'A disabled case is never the default.');
  }

  public function testLineValidation(): void {
    $case = DevelopmentCase::create(['id' => 'tiny', 'label' => 'Tiny']);
    $case->setLines([
      ['element_type' => 'discipline', 'target' => ''],
      ['element_type' => 'activity_definition', 'target' => 'nope'],
      ['element_type' => 'readiness', 'target' => ''],
      ['element_type' => 'discipline', 'target' => 'test', 'level' => 'mandatory', 'milestone_type' => 'someday'],
      ['element_type' => 'whatever', 'target' => 'x'],
    ]);
    $errors = array_map('strval', $case->validateLines());
    $this->assertSame([
      'Line 1: a discipline line needs a discipline.',
      'Line 2: <em class="placeholder">nope</em> does not exist.',
      'Line 3: a readiness line needs a criterion.',
      'Line 4: unknown level.',
      'Line 4: unknown milestone.',
      'Line 5: unknown element type.',
    ], $errors);
  }

  public function testAccess(): void {
    $user = $this->createUser(['use unified process']);
    $manager = $this->createUser(['administer unified process']);
    $outsider = $this->createUser([]);
    $discipline = ProcessDiscipline::load('test');

    $this->assertTrue($discipline->access('view', $user));
    $this->assertFalse($discipline->access('view', $outsider));
    $this->assertFalse($discipline->access('update', $user));
    $this->assertTrue($discipline->access('update', $manager));

    // Roles, definitions and cases use the Test discipline.
    $this->assertFalse($discipline->access('delete', $manager));
    $unused = ProcessDiscipline::create(['id' => 'unused', 'label' => 'Unused']);
    $unused->save();
    $this->assertTrue($unused->access('delete', $manager));
    $this->assertFalse($unused->access('delete', $user));
  }

  public function testCaseFormBuildsAndKeepsLines(): void {
    $this->setCurrentUser($this->createUser(['administer unified process']));
    $case = DevelopmentCase::load('brownfield');
    $form_object = \Drupal::entityTypeManager()->getFormObject('development_case', 'edit')->setEntity($case);
    $form_state = new FormState();
    $form = \Drupal::formBuilder()->buildForm($form_object, $form_state);
    $this->assertCount(36, array_filter(array_keys($form['lines']), 'is_int'));
    $this->assertSame('work_product_definition:current_implementation_baseline', $form['lines'][7]['element']['#default_value']);
    $this->assertArrayHasKey('readiness:iterations_planned', $form['lines'][14]['element']['#options']['Readiness Criterion']);
  }

  public function testCaseFormSaveKeepsLinesAndVersion(): void {
    $this->setCurrentUser($this->createUser(['administer unified process']));
    $case = DevelopmentCase::load('standard');
    $version = $case->getVersion();
    $form_object = \Drupal::entityTypeManager()->getFormObject('development_case', 'edit')->setEntity($case);
    $form_state = (new FormState())->setValues(['label' => 'Standard process', 'op' => 'Save']);
    \Drupal::formBuilder()->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $saved = DevelopmentCase::load('standard');
    $this->assertSame('Standard process', $saved->label());
    $this->assertSame($case->getLines(), $saved->getLines());
    $this->assertSame($version, $saved->getVersion());
  }

}
