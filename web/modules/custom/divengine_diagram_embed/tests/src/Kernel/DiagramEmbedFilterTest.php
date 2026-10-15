<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\filter\FilterProcessResult;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the <drupal-diagram> text filter.
 */
#[RunTestsInSeparateProcesses]
class DiagramEmbedFilterTest extends DiagramEmbedKernelTestBase {

  protected function filter(string $text): FilterProcessResult {
    /** @var \Drupal\filter\Plugin\FilterInterface $filter */
    $filter = $this->container->get('plugin.manager.filter')->createInstance('divengine_diagram_embed', ['status' => TRUE]);
    return $filter->process($text, 'en');
  }

  public function testDiagramWithPreviewRendersAFrame(): void {
    $diagram = $this->createDiagram(['label' => 'Order <flow>']);
    $diagram->setPreview('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480.5"><rect/></svg>');
    $diagram->save();

    $result = $this->filter('<p>Intro</p>' . $this->embed($diagram->uuid(), ['data-align' => 'center']) . '<p>Outro</p>');
    $html = $result->getProcessedText();
    $preview = $diagram->toUrl()->toString();
    $this->assertStringContainsString('<p>Intro</p>', $html);
    $this->assertStringContainsString('<p>Outro</p>', $html);
    $this->assertStringNotContainsString('drupal-diagram', $html);
    $this->assertStringContainsString('divengine-diagram-embed--ready', $html);
    $this->assertStringContainsString('divengine-diagram-embed--align-center', $html);
    $this->assertStringContainsString('style="--divengine-diagram-width: 640px"', $html);
    $this->assertStringContainsString('style="--divengine-diagram-ratio: 640 / 480.5"', $html);
    $this->assertStringContainsString('src="/diagram/' . $diagram->id() . '/preview/' . $diagram->getPreviewHash() . '.svg?fit=1"', $html);
    $this->assertStringContainsString('sandbox="allow-top-navigation-by-user-activation allow-popups"', $html);
    $this->assertStringContainsString('title="Diagram: Order &lt;flow&gt;"', $html);
    $this->assertStringContainsString('<a href="' . $preview . '">Order &lt;flow&gt;</a>', $html);
    $this->assertContains('diagram:' . $diagram->id(), $result->getCacheTags());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertSame(['divengine_diagram_embed/embed'], $result->getAttachments()['library']);
  }

  public function testAlignmentFromTheAlignFilter(): void {
    $diagram = $this->createDiagram();
    $html = $this->filter('<drupal-diagram data-diagram-uuid="' . $diagram->uuid() . '" class="align-right"></drupal-diagram>')->getProcessedText();
    $this->assertStringContainsString('divengine-diagram-embed--align-right', $html);
    $html = $this->filter($this->embed($diagram->uuid(), ['data-align' => 'sideways']))->getProcessedText();
    $this->assertStringNotContainsString('--align-', $html);
  }

  public function testDiagramWithoutPreview(): void {
    $diagram = $this->createDiagram(['label' => 'Draft only']);
    $html = $this->filter($this->embed($diagram->uuid()))->getProcessedText();
    $this->assertStringContainsString('divengine-diagram-embed--no-preview', $html);
    $this->assertStringContainsString('This diagram has no preview yet.', $html);
    $this->assertStringContainsString('>Draft only</a>', $html);
    $this->assertStringNotContainsString('<iframe', $html);
  }

  public function testRestrictedAndMissingDiagramsRevealNothing(): void {
    $diagram = $this->createDiagram(['label' => 'Secret plan']);
    $diagram->setPreview($this->svg());
    $diagram->save();
    $this->setCurrentUser($this->createUser([]));

    $result = $this->filter($this->embed($diagram->uuid()));
    $html = $result->getProcessedText();
    $this->assertStringContainsString('Restricted diagram.', $html);
    $this->assertStringNotContainsString('Secret plan', $html);
    $this->assertStringNotContainsString('/diagram/', $html);
    $this->assertContains('diagram:' . $diagram->id(), $result->getCacheTags());

    $result = $this->filter($this->embed('0b0a6d3c-0000-4000-8000-000000000000') . $this->embed(''));
    $this->assertSame(2, substr_count($result->getProcessedText(), 'Missing diagram.'));
    $this->assertContains('diagram_list', $result->getCacheTags());
  }

  public function testTextWithoutEmbedsIsUntouched(): void {
    $result = $this->filter('<p>Plain <strong>text</strong></p>');
    $this->assertSame('<p>Plain <strong>text</strong></p>', $result->getProcessedText());
    $this->assertSame([], $result->getCacheTags());
  }

  public function testPreviewFitsItsFrame(): void {
    $diagram = $this->createDiagram();
    $diagram->setPreview('<svg xmlns="http://www.w3.org/2000/svg" width="200px" height="100" style="width: 200px; height:100px; background: white"><rect/></svg>');
    $diagram->save();
    $builder = $this->container->get('divengine_modeler.preview_builder');
    $svg = $builder->build($diagram, $this->container->get('current_user'), TRUE);
    $this->assertStringContainsString('width="100%" height="100%"', $svg);
    $this->assertStringContainsString('viewBox="0 0 200 100"', $svg);
    $this->assertStringContainsString('preserveAspectRatio="xMidYMid meet"', $svg);
    $this->assertStringContainsString('style="background: white"', $svg);
    $this->assertStringContainsString('width="200px"', $builder->build($diagram, $this->container->get('current_user')));
  }

}
