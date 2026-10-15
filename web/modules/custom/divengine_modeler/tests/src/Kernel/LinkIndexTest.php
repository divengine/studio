<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the element link index and backlinks.
 */
#[RunTestsInSeparateProcesses]
class LinkIndexTest extends ModelerKernelTestBase {

  protected function index() {
    return $this->container->get('divengine_modeler.link_index');
  }

  public function testIndexIsRebuiltFromSourceAndBindings(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $diagram = $this->createDiagram([
      'source' => "SKETCH\nlink a entity:diagram/{$target->id()}\nlink b javascript:x\n",
    ]);
    $rows = $this->index()->linksOf((int) $diagram->id());
    $this->assertCount(1, $rows);
    $this->assertSame('a', $rows[0]->element_id);
    $this->assertSame('diagram', $rows[0]->target_type);
    $this->assertSame((string) $target->id(), $rows[0]->target_id);
    $this->assertSame('0', (string) $rows[0]->broken);
    $this->assertSame((string) $diagram->getRevisionId(), (string) $rows[0]->revision_id);

    $diagram->setSource("SKETCH\n");
    $diagram->setBindings(['entity:B' => ['entity' => 'entity:diagram/' . $target->id(), 'label' => 'Target']]);
    $diagram->save();
    $rows = $this->index()->linksOf((int) $diagram->id());
    $this->assertCount(1, $rows);
    $this->assertSame('entity:B', $rows[0]->element_id);
    $this->assertSame('Target', $rows[0]->label);
  }

  public function testMissingTargetsAreIndexedAsBroken(): void {
    $diagram = $this->createDiagram(['source' => "SKETCH\nlink a entity:diagram/999\nlink b entity:nonexistent/1\nlink c entity:diagram/abc\n"]);
    $rows = $this->index()->linksOf((int) $diagram->id());
    $this->assertCount(3, $rows);
    foreach ($rows as $row) {
      $this->assertSame('1', (string) $row->broken, $row->element_id);
    }
  }

  public function testDeletedTargetIsFlaggedAndDocumentKept(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $source = "SKETCH\nlink a entity:diagram/{$target->id()}\n";
    $diagram = $this->createDiagram(['source' => $source]);
    $target->delete();
    $rows = $this->index()->linksOf((int) $diagram->id());
    $this->assertSame('1', (string) $rows[0]->broken);
    $this->assertSame($source, $this->reload($diagram)->getSource());
  }

  public function testDeletedDiagramLeavesNoRows(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $diagram = $this->createDiagram(['source' => "SKETCH\nlink a entity:diagram/{$target->id()}\n"]);
    $diagram->delete();
    $this->assertSame([], $this->index()->linksOf((int) $diagram->id()));
    $this->assertSame([], $this->index()->diagramsLinkingTo('diagram', (string) $target->id()));
  }

  public function testBacklinksOnlyListViewableDiagrams(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $first = $this->createDiagram(['label' => 'First', 'source' => "SKETCH\nlink a entity:diagram/{$target->id()}\n"]);
    $second = $this->createDiagram(['label' => 'Second', 'source' => "SKETCH\nlink x entity:diagram/{$target->id()}\nlink y entity:diagram/{$target->id()}\n"]);
    $this->assertSame([(int) $first->id(), (int) $second->id()], $this->index()->diagramsLinkingTo('diagram', (string) $target->id()));

    $backlinks = $this->container->get('divengine_modeler.backlinks');
    $viewer = $this->createUser(['view diagrams']);
    $this->assertCount(2, $backlinks->load($target, $viewer));
    $stranger = $this->createUser([]);
    $this->assertSame([], $backlinks->load($target, $stranger));
  }

  public function testPendingDraftIsWhatTheIndexDescribes(): void {
    $target = $this->createDiagram(['label' => 'Target']);
    $diagram = $this->createDiagram();
    $diagram->set('moderation_state', 'review');
    $diagram->save();
    $diagram->set('moderation_state', 'published');
    $diagram->save();
    // A new draft of an approved diagram: the index follows the working copy.
    $diagram->setSource("SKETCH\nlink a entity:diagram/{$target->id()}\n");
    $diagram->set('moderation_state', 'draft');
    $diagram->save();
    $this->assertCount(1, $this->index()->linksOf((int) $diagram->id()));
    $this->assertSame("SKETCH\n", $this->reload($diagram)->getSource());
  }

}
