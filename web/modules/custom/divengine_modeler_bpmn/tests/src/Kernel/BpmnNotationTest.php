<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler_bpmn\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_modeler\Entity\Diagram;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the BPMN notation: validation, links and search text.
 */
#[RunTestsInSeparateProcesses]
class BpmnNotationTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'workflows',
    'content_moderation',
    'views',
    'divengine_core',
    'divengine_modeler',
    'divengine_modeler_bpmn',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('diagram');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('divengine_modeler', ['diagram_element_link']);
    $this->installConfig(['content_moderation', 'divengine_modeler', 'divengine_modeler_bpmn']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  protected function notation() {
    return $this->container->get('plugin.manager.modeler_notation')->createInstance('bpmn');
  }

  protected function document(string $process): string {
    return '<?xml version="1.0" encoding="UTF-8"?>'
      . '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:divengine="https://divengine.com/schema/modeler/1.0" id="D" targetNamespace="https://example.com">'
      . '<bpmn:process id="P">' . $process . '</bpmn:process></bpmn:definitions>';
  }

  public function testTemplateIsValidAndUsedForNewDiagrams(): void {
    $notation = $this->notation();
    $this->assertSame([], $notation->validateSource($notation->defaultSource()));
    $diagram = Diagram::create(['type' => 'bpmn', 'label' => 'Order to cash']);
    $diagram->save();
    $this->assertSame($notation->defaultSource(), $diagram->getSource());
    $this->assertSame('canvas', $notation->family());
  }

  public function testInvalidDocumentsAreRejected(): void {
    $notation = $this->notation();
    $this->assertNotSame([], $notation->validateSource('<definitions/>'), 'Wrong namespace.');
    $this->assertNotSame([], $notation->validateSource('<bpmn:process xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"/>'), 'Wrong root.');
    $this->assertNotSame([], $notation->validateSource('<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL">'), 'Not well-formed.');
    $this->assertNotSame([], $notation->validateSource(''), 'Empty.');
    $this->assertNotSame([], $notation->validateSource(str_repeat(' ', 1_000_001)), 'Too large.');
    $xxe = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
      . '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"><bpmn:documentation>&x;</bpmn:documentation></bpmn:definitions>';
    $errors = $notation->validateSource($xxe);
    $this->assertStringContainsString('Document type declarations', implode(' ', $errors));
    $this->assertSame('', $notation->extractSearchText($xxe));
  }

  public function testLinksComeFromDivengineAttributes(): void {
    $source = $this->document(
      '<bpmn:task id="Task_1" name="Approve" divengine:entity="entity:artifact/7" divengine:label="REQ-7"/>'
      . '<bpmn:task id="Task_2" name="Ship" divengine:entity="https://evil.example"/>'
      . '<bpmn:task id="Task_3" name="Bill" entity="entity:node/1"/>'
      . '<bpmn:subProcess id="Sub_1" divengine:entity="entity:diagram/3"/>'
    );
    $this->assertSame([
      ['element' => 'Task_1', 'entity' => 'entity:artifact/7', 'label' => 'REQ-7'],
      ['element' => 'Sub_1', 'entity' => 'entity:diagram/3', 'label' => ''],
    ], $this->notation()->extractLinks($source));
  }

  public function testSearchTextHasNamesAndDocumentation(): void {
    $source = $this->document(
      '<bpmn:task id="T1" name="Approve   order"><bpmn:documentation>Checks credit.</bpmn:documentation></bpmn:task>'
      . '<bpmn:task id="T2" name="Approve   order"/>'
      . '<bpmn:textAnnotation id="A"><bpmn:text>Only weekdays</bpmn:text></bpmn:textAnnotation>'
    );
    $this->assertSame("Approve order\nChecks credit.\nOnly weekdays", $this->notation()->extractSearchText($source));
  }

  public function testSavedDiagramIndexesItsLinks(): void {
    $target = Diagram::create(['type' => 'bpmn', 'label' => 'Sub-process']);
    $target->save();
    $diagram = Diagram::create([
      'type' => 'bpmn',
      'label' => 'Main',
      'source' => $this->document('<bpmn:callActivity id="Call_1" name="Run sub" divengine:entity="entity:diagram/' . $target->id() . '" divengine:label="Sub-process"/>'),
    ]);
    $diagram->save();
    $rows = $this->container->get('divengine_modeler.link_index')->linksOf((int) $diagram->id());
    $this->assertCount(1, $rows);
    $this->assertSame('Call_1', $rows[0]->element_id);
    $this->assertSame([(int) $diagram->id()], $this->container->get('divengine_modeler.link_index')->diagramsLinkingTo('diagram', (string) $target->id()));
  }

}
