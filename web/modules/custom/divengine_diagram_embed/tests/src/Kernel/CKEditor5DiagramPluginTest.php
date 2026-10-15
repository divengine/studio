<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_diagram_embed\Kernel;

use Drupal\ckeditor5\Plugin\Editor\CKEditor5;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the CKEditor 5 diagram plugin definition.
 */
#[RunTestsInSeparateProcesses]
class CKEditor5DiagramPluginTest extends DiagramEmbedKernelTestBase {

  protected function pair(array $filters, string $allowed_html): array {
    $format = FilterFormat::create([
      'format' => 'engineering',
      'name' => 'Engineering',
      'filters' => [
        'filter_html' => [
          'id' => 'filter_html',
          'provider' => 'filter',
          'status' => TRUE,
          'weight' => -10,
          'settings' => [
            'allowed_html' => $allowed_html,
            'filter_html_help' => TRUE,
            'filter_html_nofollow' => FALSE,
          ],
        ],
      ] + $filters,
    ]);
    $editor = Editor::create([
      'format' => 'engineering',
      'editor' => 'ckeditor5',
      'image_upload' => ['status' => FALSE],
      'settings' => [
        'toolbar' => ['items' => ['bold', 'divengineDiagram']],
        'plugins' => [],
      ],
    ]);
    $messages = [];
    foreach (CKEditor5::validatePair($editor, $format) as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
    }
    return [$messages, $editor, $format];
  }

  public function testValidPairAndDynamicConfig(): void {
    [$messages, $editor, $format] = $this->pair(
      [
        'divengine_diagram_embed' => [
          'id' => 'divengine_diagram_embed',
          'provider' => 'divengine_diagram_embed',
          'status' => TRUE,
          'weight' => 100,
          'settings' => [],
        ],
      ],
      '<br> <p> <strong> <drupal-diagram data-diagram-uuid data-align>',
    );
    $this->assertSame([], $messages);
    $format->save();
    $editor->save();

    $manager = $this->container->get('plugin.manager.ckeditor5.plugin');
    $config = $manager->getCKEditor5PluginConfig($editor);
    $this->assertContains('divengineDiagram.DivengineDiagram', $config['plugins']);
    $this->assertSame('/divengine/diagram-embed/search', $config['config']['divengineDiagram']['searchUrl']);
    $this->assertSame('/divengine/diagram-embed/lookup', $config['config']['divengineDiagram']['lookupUrl']);
    $this->assertContains('divengine_diagram_embed/ckeditor5', $manager->getEnabledLibraries($editor));
  }

  public function testTheButtonRequiresTheFilter(): void {
    [$messages] = $this->pair([], '<br> <p> <strong> <drupal-diagram data-diagram-uuid data-align>');
    $this->assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, 'Diagram')), implode("\n", $messages));
  }

}
