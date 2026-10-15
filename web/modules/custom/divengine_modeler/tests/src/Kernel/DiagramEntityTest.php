<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use Drupal\divengine_modeler\Entity\Diagram;
use Drupal\divengine_modeler\Entity\DiagramType;
use Drupal\workflows\Entity\Workflow;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the diagram entity, its type and its lifecycle.
 */
#[RunTestsInSeparateProcesses]
class DiagramEntityTest extends ModelerKernelTestBase {

  public function testDiagramTypesJoinTheLifecycle(): void {
    $plugin = Workflow::load('diagram_lifecycle')->getTypePlugin();
    $this->assertSame(['sketch'], $plugin->getBundlesForEntityType('diagram'));

    DiagramType::create(['id' => 'second', 'label' => 'Second', 'notation' => 'sketch'])->save();
    $plugin = Workflow::load('diagram_lifecycle')->getTypePlugin();
    $this->assertContains('second', $plugin->getBundlesForEntityType('diagram'));
  }

  public function testTypeDependsOnTheNotationModule(): void {
    $dependencies = DiagramType::load('sketch')->calculateDependencies()->getDependencies();
    $this->assertContains('divengine_modeler_test', $dependencies['module']);
  }

  public function testNewDiagramStartsFromTemplateAsDraft(): void {
    $diagram = $this->createDiagram();
    $this->assertSame("SKETCH\n", $diagram->getSource());
    $this->assertSame('draft', $diagram->get('moderation_state')->value);
    $this->assertFalse($diagram->isPublished());
  }

  public function testSearchTextComesFromTheSource(): void {
    $diagram = $this->createDiagram(['source' => "SKETCH\nbox a Billing service\nbox b Invoice store\n"]);
    $this->assertSame("Billing service\nInvoice store", $diagram->get('search_text')->value);
  }

  public function testInvalidSourceIsRejected(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Bad', 'source' => 'NOT A SKETCH']);
    $violations = $diagram->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('source', $violations[0]->getPropertyPath());
  }

  public function testOversizedSourceIsRejected(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Big', 'source' => 'SKETCH' . str_repeat('x', 1_000_000)]);
    $this->assertStringContainsString('byte limit', (string) $diagram->validate()[0]->getMessage());
  }

  public function testBindingsMustPointToEntityUris(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Bindings']);
    $diagram->setBindings(['entity:A' => ['entity' => 'entity:node/1', 'label' => 'A node']]);
    $this->assertCount(0, $diagram->validate());

    $diagram->setBindings(['entity:A' => ['entity' => 'javascript:alert(1)']]);
    $this->assertSame('element_bindings', $diagram->validate()[0]->getPropertyPath());

    $diagram->setBindings(['entity:A' => ['entity' => 'entity:node/1', 'extra' => 'x']]);
    $this->assertSame('element_bindings', $diagram->validate()[0]->getPropertyPath());
  }

  public function testUnsafePreviewIsRejected(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Unsafe']);
    $diagram->setPreview($this->svg('<script>alert(1)</script>'));
    $this->assertSame('preview_svg', $diagram->validate()[0]->getPropertyPath());
  }

  public function testPreviewFreshnessFollowsTheSource(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Fresh', 'source' => "SKETCH\nbox a A\n"]);
    $this->assertFalse($diagram->hasFreshPreview());
    $diagram->setPreview($this->svg());
    $this->assertTrue($diagram->hasFreshPreview());
    $diagram->setSource("SKETCH\nbox a B\n");
    $this->assertFalse($diagram->hasFreshPreview());
  }

  public function testBindingsRoundTrip(): void {
    $diagram = Diagram::create(['type' => 'sketch', 'label' => 'Round trip']);
    $diagram->setBindings([
      'link:A->B:dependency' => ['entity' => 'entity:diagram/9', 'label' => 'Nine'],
      'entity:A' => ['entity' => 'entity:node/1', 'label' => 'One'],
    ]);
    $diagram->save();
    $bindings = $this->reload($diagram)->getBindings();
    $this->assertSame(['entity:A', 'link:A->B:dependency'], array_keys($bindings));
    $this->assertSame('entity:diagram/9', $bindings['link:A->B:dependency']['entity']);
  }

}
