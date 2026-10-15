<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers how stored previews are prepared for each viewer.
 */
#[RunTestsInSeparateProcesses]
class PreviewBuilderTest extends ModelerKernelTestBase {

  public function testLinkedElementsHonourTargetAccess(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $diagram = $this->createDiagram([
      'source' => "SKETCH\nlink a entity:diagram/{$target->id()}\n",
    ]);
    $diagram->setPreview($this->svg(
      '<g data-divengine-element="a"><rect/></g>'
      . '<g data-divengine-element="unlinked"><rect/></g>'
      . '<a href="entity:diagram/' . $target->id() . '"><text>native</text></a>'
    ));
    $diagram->save();

    $builder = $this->container->get('divengine_modeler.preview_builder');
    $viewer = $this->createUser(['view diagrams']);
    $svg = $builder->build($diagram, $viewer);
    $url = $target->toUrl()->toString();
    $this->assertStringContainsString('<a href="' . $url . '" target="_top"><g data-divengine-element="a" data-entity-uri="entity:diagram/' . $target->id() . '">', $svg);
    $this->assertStringContainsString('<a href="' . $url . '"><text>native</text></a>', $svg);
    $this->assertStringContainsString('<g data-divengine-element="unlinked">', $svg);

    $stranger = $this->createUser([]);
    $svg = $builder->build($diagram, $stranger);
    $this->assertStringContainsString('data-divengine-restricted="1"', $svg);
    $this->assertStringNotContainsString($url, $svg);
    $this->assertStringNotContainsString('entity:diagram/' . $target->id(), $svg);
  }

}
