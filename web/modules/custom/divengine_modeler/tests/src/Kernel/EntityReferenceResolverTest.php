<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers how the picker finds and resolves link targets.
 */
#[RunTestsInSeparateProcesses]
class EntityReferenceResolverTest extends ModelerKernelTestBase {

  public function testResolvesUrisPathsAndLocalUrls(): void {
    $diagram = $this->createDiagram(['label' => 'Billing flow']);
    $resolver = $this->container->get('divengine_modeler.entity_resolver');
    $id = $diagram->id();

    $this->assertSame($id, $resolver->resolve("entity:diagram/$id")?->id());
    $this->assertSame($id, $resolver->resolve("/diagram/$id")?->id());
    $this->assertSame($id, $resolver->resolve("/diagram/$id/edit?x=1")?->id());
    $this->assertSame($id, $resolver->resolve("https://studio.example/diagram/$id", 'studio.example')?->id());
    $this->assertNull($resolver->resolve("https://elsewhere.example/diagram/$id", 'studio.example'));
    $this->assertNull($resolver->resolve('/no/such/path'));
    $this->assertNull($resolver->resolve('entity:diagram/999'));
    $this->assertNull($resolver->resolve('javascript:alert(1)'));
    // Users are not link targets by default.
    $this->assertNull($resolver->resolve('/user/1'));
  }

  public function testSearchReturnsOnlyViewableMatches(): void {
    $this->createDiagram(['label' => 'Billing flow']);
    $this->createDiagram(['label' => 'Shipping flow']);
    $resolver = $this->container->get('divengine_modeler.entity_resolver');

    $results = $resolver->search('billing', $this->createUser(['view diagrams']));
    $this->assertCount(1, $results);
    $this->assertSame('Billing flow', $results[0]['label']);
    $this->assertStringStartsWith('entity:diagram/', $results[0]['entity']);

    $this->assertCount(2, $resolver->search('flow', $this->createUser(['view diagrams'])));
    $this->assertSame([], $resolver->search('flow', $this->createUser([])));
    $this->assertSame([], $resolver->search('f', $this->createUser(['view diagrams'])));
  }

}
