<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\divengine_diagram_embed\Controller\DiagramEmbedController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the search and lookup endpoints of the diagram button.
 */
#[RunTestsInSeparateProcesses]
class DiagramEmbedControllerTest extends DiagramEmbedKernelTestBase {

  protected function controller(): DiagramEmbedController {
    return DiagramEmbedController::create($this->container);
  }

  protected function json(string $method, array $query): array {
    $response = $this->controller()->{$method}(Request::create('/', 'GET', $query));
    return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
  }

  public function testSearchAndLookupHonourAccess(): void {
    $mine = $this->createDiagram(['label' => 'Order flow']);
    $mine->setPreview($this->svg());
    $mine->save();
    $this->createDiagram(['label' => 'Billing classes']);

    [$status, $body] = $this->json('search', ['q' => 'order']);
    $this->assertSame(200, $status);
    $this->assertSame(['Order flow'], array_column($body['results'], 'label'));
    $this->assertSame($mine->uuid(), $body['results'][0]['uuid']);
    $this->assertSame('Sketch', $body['results'][0]['type']);
    $this->assertStringEndsWith('/preview/' . $mine->getPreviewHash() . '.svg?fit=1', $body['results'][0]['previewUrl']);

    [, $body] = $this->json('search', []);
    $this->assertCount(2, $body['results']);
    [, $body] = $this->json('search', ['q' => 'billing']);
    $this->assertNull($body['results'][0]['previewUrl']);

    [$status, $body] = $this->json('lookup', ['uuid' => $mine->uuid()]);
    $this->assertSame(200, $status);
    $this->assertSame('Order flow', $body['result']['label']);
    [$status] = $this->json('lookup', ['uuid' => 'nope']);
    $this->assertSame(404, $status);

    $this->setCurrentUser($this->createUser([]));
    [$status, $body] = $this->json('lookup', ['uuid' => $mine->uuid()]);
    $this->assertSame(403, $status);
    $this->assertSame(['state' => 'restricted'], $body);
    [, $body] = $this->json('search', []);
    $this->assertSame([], $body['results']);
  }

}
