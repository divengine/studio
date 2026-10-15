<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler_uml\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\divengine_modeler\Entity\Diagram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the UML notation: source checks, bindings, links and previews.
 */
#[RunTestsInSeparateProcesses]
class UmlNotationTest extends KernelTestBase {

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
    'divengine_modeler_uml',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('diagram');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('divengine_modeler', ['diagram_element_link']);
    $this->installConfig(['content_moderation', 'divengine_modeler', 'divengine_modeler_uml']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  protected function notation() {
    return $this->container->get('plugin.manager.modeler_notation')->createInstance('uml');
  }

  public function testTemplateIsValidAndUsedForNewDiagrams(): void {
    $notation = $this->notation();
    $this->assertSame([], $notation->validateSource($notation->defaultSource()));
    $this->assertSame('text', $notation->family());
    $diagram = Diagram::create(['type' => 'uml', 'label' => 'Ordering']);
    $diagram->save();
    $this->assertSame($notation->defaultSource(), $diagram->getSource());
    $this->assertStringEndsWith('plantuml.js?v=1.2026.8', $notation->clientSettings($diagram)['engineUrl']);
  }

  public function testOtherPlantUmlDiagramsAreAccepted(): void {
    $this->assertSame([], $this->notation()->validateSource("@startmindmap\n* Root\n@endmindmap\n"));
    $this->assertSame([], $this->notation()->validateSource("' comment\n@startuml\n!theme plain\nA -> B\n@enduml"));
  }

  public static function invalidProvider(): array {
    return [
      'no start' => ["class A\n", 'starts with'],
      'no end' => ["@startuml\nclass A\n", '@enduml'],
      'mismatched end' => ["@startuml\nclass A\n@endmindmap\n", '@enduml'],
      'include url' => ["@startuml\n!include https://example.com/a.puml\n@enduml", 'Includes'],
      'include file' => ["@startuml\n  !include_once /etc/passwd\n@enduml", 'Includes'],
      'include stdlib' => ["@startuml\n!include <C4/C4_Context>\n@enduml", 'Includes'],
      'includeurl' => ["@startuml\n!includeurl https://example.com/a\n@enduml", 'Includes'],
      'import' => ["@startuml\n!import lib.zip\n@enduml", 'Includes'],
      'remote theme' => ["@startuml\n!theme amiga from https://example.com/themes\n@enduml", 'Themes'],
      'load json' => ["@startuml\n!\$data = %load_json(\"https://example.com/x.json\")\n@enduml", 'JSON'],
      'remote image' => ["@startuml\nclass A <img:https://example.com/x.png>\n@enduml", 'Images'],
      'absolute image' => ["@startuml\nclass A <img:/etc/x.png>\n@enduml", 'Images'],
      'script link' => ["@startuml\nclass A [[javascript:alert(1)]]\n@enduml", 'script'],
    ];
  }

  #[DataProvider('invalidProvider')]
  public function testInvalidSources(string $source, string $message): void {
    $this->assertStringContainsString($message, implode(' ', $this->notation()->validateSource($source)));
  }

  public function testOversizedSourceIsRejected(): void {
    $this->assertStringContainsString('byte limit', implode(' ', $this->notation()->validateSource("@startuml\n" . str_repeat('x', 1_000_000) . "\n@enduml")));
  }

  public function testLinksComeFromBindingsAndNativeSyntax(): void {
    $source = "@startuml\nclass Invoice [[entity:artifact/7 REQ-7]]\nclass Order [[entity:node/3{tooltip}]]\nclass Web [[https://example.com]]\n@enduml";
    $links = $this->notation()->extractLinks($source, [
      'entity:Customer' => ['entity' => 'entity:node/1', 'label' => 'ACME'],
      'entity:Broken' => ['entity' => 'not-a-uri'],
    ]);
    $this->assertSame([
      ['element' => 'entity:Customer', 'entity' => 'entity:node/1', 'label' => 'ACME'],
      ['element' => 'line:2', 'entity' => 'entity:artifact/7', 'label' => 'REQ-7'],
      ['element' => 'line:3', 'entity' => 'entity:node/3', 'label' => ''],
    ], $links);
  }

  public function testSearchTextSkipsDirectivesAndComments(): void {
    $source = "@startuml\n' a comment\n/' block\ncomment '/\n!theme plain\nskinparam monochrome true\nactor Customer\nusecase \"Pay invoice\" as UC1 [[entity:node/1]]\n@enduml";
    $this->assertSame("actor Customer\nusecase \"Pay invoice\" as UC1", $this->notation()->extractSearchText($source));
  }

  public function testBindingsAreIndexedAndRenderedForViewers(): void {
    $target = Diagram::create(['type' => 'uml', 'label' => 'Invoice model']);
    $target->save();
    $source = "@startuml\npackage billing {\n  class Invoice\n  class Customer\n}\nCustomer --> Invoice : owns\nCustomer --> Invoice\ninterface Payable\nInvoice ..|> Payable\n@enduml";
    $diagram = Diagram::create(['type' => 'uml', 'label' => 'Billing', 'source' => $source]);
    $diagram->setBindings(['entity:billing.Invoice' => ['entity' => 'entity:diagram/' . $target->id(), 'label' => 'Invoice model']]);
    // An export produced by uml-notation.js from this source with the vendored
    // engine (see tests/js).
    $diagram->setPreview(file_get_contents(__DIR__ . '/../../fixtures/class-diagram.svg'));
    $this->assertCount(0, $diagram->validate(), 'The engine output passes the SVG safety check.');
    $diagram->save();

    $rows = $this->container->get('divengine_modeler.link_index')->linksOf((int) $diagram->id());
    $this->assertSame('entity:billing.Invoice', $rows[0]->element_id);

    $svg = $this->container->get('divengine_modeler.preview_builder')->build($diagram, $this->createUser(['view diagrams']));
    $this->assertMatchesRegularExpression('#<a href="[^"]*/diagram/' . $target->id() . '" target="_top"><g class="entity" data-qualified-name="billing.Invoice"#', $svg);
  }

}
