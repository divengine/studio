<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\divengine_diagram_embed\DiagramUsage;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the diagram usage table and the "Embedded in" list.
 */
#[RunTestsInSeparateProcesses]
class DiagramUsageTest extends DiagramEmbedKernelTestBase {

  protected function usage(): DiagramUsage {
    return $this->container->get('divengine_diagram_embed.usage');
  }

  protected function rows(int $diagram_id): array {
    return array_map(static fn (object $row): string => "$row->host_type/$row->host_id/$row->field_name/$row->langcode x$row->count", $this->usage()->usagesOf($diagram_id));
  }

  public function testHostSavesAndDeletesKeepTheTableInSync(): void {
    $a = $this->createDiagram(['label' => 'A']);
    $b = $this->createDiagram(['label' => 'B']);
    $host = EntityTest::create([
      'name' => 'Spec',
      'body' => [
        'value' => '<p>x</p>' . $this->embed($a->uuid()) . $this->embed($a->uuid()) . $this->embed('unknown-uuid'),
        'format' => 'plain_text',
      ],
    ]);
    $host->save();
    $this->assertSame(["entity_test/{$host->id()}/body/en x2"], $this->rows((int) $a->id()));
    $this->assertSame([], $this->rows((int) $b->id()));
    $this->assertSame([(int) $a->id() => 2], $this->usage()->diagramsIn('entity_test', (string) $host->id()));

    $host->set('body', ['value' => $this->embed($b->uuid()), 'format' => 'plain_text'])->save();
    $this->assertSame([], $this->rows((int) $a->id()));
    $this->assertSame(["entity_test/{$host->id()}/body/en x1"], $this->rows((int) $b->id()));

    $host->delete();
    $this->assertSame([], $this->rows((int) $b->id()));
  }

  public function testOnlyTheDefaultRevisionCounts(): void {
    $diagram = $this->createDiagram();
    $host = EntityTestRev::create(['name' => 'Doc', 'body' => ['value' => '<p>none</p>', 'format' => 'plain_text']]);
    $host->save();

    $host->setNewRevision();
    $host->isDefaultRevision(FALSE);
    $host->set('body', ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text'])->save();
    $this->assertSame([], $this->rows((int) $diagram->id()));

    $host = EntityTestRev::load($host->id());
    $host->setNewRevision();
    $host->set('body', ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text'])->save();
    $this->assertSame(["entity_test_rev/{$host->id()}/body/en x1"], $this->rows((int) $diagram->id()));
  }

  public function testDeletedDiagramIsForgotten(): void {
    $diagram = $this->createDiagram();
    EntityTest::create(['name' => 'Spec', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']])->save();
    $this->assertCount(1, $this->rows((int) $diagram->id()));
    $id = (int) $diagram->id();
    $diagram->delete();
    $this->assertSame([], $this->rows($id));
  }

  public function testRebuildAllFindsExistingContent(): void {
    $diagram = $this->createDiagram();
    $host = EntityTest::create(['name' => 'Old', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']]);
    $host->save();
    \Drupal::database()->truncate(DiagramUsage::TABLE)->execute();
    $this->assertSame([], $this->rows((int) $diagram->id()));

    $this->assertGreaterThanOrEqual(1, $this->usage()->rebuildAll());
    $this->assertSame(["entity_test/{$host->id()}/body/en x1"], $this->rows((int) $diagram->id()));
  }

  public function testEmbeddedInListShowsOnlyViewableHosts(): void {
    $diagram = $this->createDiagram();
    $visible = EntityTest::create(['name' => 'Visible spec', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']]);
    $visible->save();
    // entity_test hides entities named "forbid_access" from everyone.
    EntityTest::create(['name' => 'forbid_access', 'body' => ['value' => $this->embed($diagram->uuid()), 'format' => 'plain_text']])->save();
    $this->setCurrentUser($this->createUser(['view diagrams', 'view test entity']));

    $build = divengine_diagram_embed_usage_list($diagram);
    $html = (string) $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn () => $this->container->get('renderer')->render($build));
    $this->assertStringContainsString('Embedded in (2)', $html);
    $this->assertStringContainsString('Visible spec', $html);
    $this->assertStringNotContainsString('forbid_access', $html);
    $this->assertStringContainsString('Also embedded in 1 item you cannot view.', $html);
    $this->assertContains(DiagramUsage::cacheTag((int) $diagram->id()), $build['#cache']['tags']);
  }

  public function testDeleteFormWarnsAboutEmbeds(): void {
    $diagram = $this->createDiagram();
    EntityTest::create(['name' => 'Spec', 'body' => ['value' => $this->embed($diagram->uuid()) . $this->embed($diagram->uuid()), 'format' => 'plain_text']])->save();
    $form = $this->container->get('entity.form_builder')->getForm($diagram, 'delete');
    $this->assertStringContainsString('embedded 2 times', (string) $form['divengine_diagram_usage']['#value']);
  }

  public function testUuidsInHtml(): void {
    $this->assertSame([], DiagramUsage::uuidsIn('<p>No embeds</p>'));
    $this->assertSame(['a', 'b', 'a'], DiagramUsage::uuidsIn('<drupal-diagram data-diagram-uuid="a"></drupal-diagram><div><DRUPAL-DIAGRAM data-diagram-uuid=" b "></DRUPAL-DIAGRAM></div><drupal-diagram data-diagram-uuid="a"></drupal-diagram><drupal-diagram></drupal-diagram>'));
  }

}
