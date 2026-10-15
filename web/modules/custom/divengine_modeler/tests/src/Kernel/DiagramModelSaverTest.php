<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use Drupal\divengine_modeler\Exception\InvalidModelException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers saving models sent by editors.
 */
#[RunTestsInSeparateProcesses]
class DiagramModelSaverTest extends ModelerKernelTestBase {

  protected function saver() {
    return $this->container->get('divengine_modeler.model_saver');
  }

  public function testSaveWritesRevisionWithPreview(): void {
    $diagram = $this->createDiagram();
    $first = (int) $diagram->getRevisionId();
    $saved = $this->saver()->save($diagram, "SKETCH\nbox a A\n", $this->svg(), [], 'Added A', \Drupal::currentUser());
    $this->assertGreaterThan($first, (int) $saved->getRevisionId());
    $this->assertSame('Added A', $saved->getRevisionLogMessage());
    $this->assertTrue($saved->hasFreshPreview());
    $this->assertSame((int) \Drupal::currentUser()->id(), (int) $saved->getRevisionUserId());
  }

  public function testUnsafeSvgSavesNothing(): void {
    $diagram = $this->createDiagram();
    try {
      $this->saver()->save($diagram, "SKETCH\nbox a A\n", $this->svg('<g onclick="x()"/>'), [], '', \Drupal::currentUser());
      $this->fail('An unsafe preview was accepted.');
    }
    catch (InvalidModelException $e) {
      $this->assertStringContainsString('event handler', implode(' ', $e->getErrors()));
    }
    $this->assertSame("SKETCH\n", $this->latest($diagram)->getSource());
  }

  public function testInvalidSourceSavesNothing(): void {
    $diagram = $this->createDiagram();
    $this->expectException(InvalidModelException::class);
    $this->saver()->save($diagram, 'garbage', NULL, [], '', \Drupal::currentUser());
  }

  public function testFailedExportKeepsSourceAndMarksPreviewStale(): void {
    $diagram = $this->createDiagram();
    $this->saver()->save($diagram, "SKETCH\nbox a A\n", $this->svg(), [], '', \Drupal::currentUser());
    $saved = $this->saver()->save($diagram, "SKETCH\nbox a B\n", NULL, [], '', \Drupal::currentUser());
    $this->assertSame("SKETCH\nbox a B\n", $saved->getSource());
    $this->assertNotSame('', $saved->getPreviewSvg());
    $this->assertFalse($saved->hasFreshPreview());
  }

  public function testApprovedDiagramIsReopenedAsDraft(): void {
    $diagram = $this->createDiagram(['source' => "SKETCH\nbox a Approved\n"]);
    $diagram->set('moderation_state', 'review');
    $diagram->save();
    $diagram->set('moderation_state', 'published');
    $diagram->save();

    $saved = $this->saver()->save($diagram, "SKETCH\nbox a Changed\n", NULL, [], '', \Drupal::currentUser());
    $this->assertSame('draft', $saved->get('moderation_state')->value);
    $default = $this->reload($diagram);
    $this->assertSame("SKETCH\nbox a Approved\n", $default->getSource());
    $this->assertSame('published', $default->get('moderation_state')->value);

    // The next save edits the pending draft, not the approved revision.
    $again = $this->saver()->save($default, "SKETCH\nbox a Changed again\n", NULL, [], '', \Drupal::currentUser());
    $this->assertSame('draft', $again->get('moderation_state')->value);
    $this->assertSame("SKETCH\nbox a Approved\n", $this->reload($diagram)->getSource());
  }

  public function testStoredPreviewMustMatchTheSource(): void {
    $diagram = $this->createDiagram(['source' => "SKETCH\nbox a A\n"]);
    $revision = (int) $diagram->getRevisionId();
    $this->saver()->storePreview($diagram, $this->svg(), $diagram::hashSource($diagram->getSource()));
    $stored = $this->reload($diagram);
    $this->assertTrue($stored->hasFreshPreview());
    $this->assertSame($revision, (int) $stored->getRevisionId(), 'Storing a preview creates no revision.');

    $this->expectException(InvalidModelException::class);
    $this->saver()->storePreview($stored, $this->svg(), $diagram::hashSource('other'));
  }

}
