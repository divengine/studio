<?php

declare(strict_types=1);

namespace Drupal\divengine_diagram_embed\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\divengine_modeler\Entity\DiagramInterface;
use Drupal\divengine_modeler\PreviewBuilder;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders <drupal-diagram data-diagram-uuid="…"> as the diagram's preview.
 *
 * The preview is the stored SVG of the diagram's default revision, shown in a
 * sandboxed frame so its styles stay inside it and its linked elements open
 * the records the viewer may see. Viewers who cannot see the diagram get a
 * neutral "restricted" notice that reveals nothing about it.
 */
#[Filter(
  id: 'divengine_diagram_embed',
  title: new TranslatableMarkup('Embed diagrams'),
  description: new TranslatableMarkup('Embeds diagrams using a custom tag, <code>&lt;drupal-diagram&gt;</code>. If used with the "Align images" filter, make sure this filter runs after it.'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: 100,
)]
class DiagramEmbed extends FilterBase implements ContainerFactoryPluginInterface {

  const ALIGNMENTS = ['left', 'center', 'right'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('renderer'),
    );
  }

  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    if (stripos($text, '<drupal-diagram') === FALSE) {
      return $result;
    }
    $document = Html::load($text);
    $elements = iterator_to_array($document->getElementsByTagName('drupal-diagram'));
    if (!$elements) {
      return $result;
    }
    foreach ($elements as $element) {
      $build = $this->buildEmbed(trim($element->getAttribute('data-diagram-uuid')), $this->alignment($element));
      $markup = $this->renderer->executeInRenderContext(new RenderContext(), fn () => $this->renderer->render($build));
      $result = $result->merge(BubbleableMetadata::createFromRenderArray($build));
      $this->replace($element, (string) $markup);
    }
    $result->setProcessedText(Html::serialize($document));
    return $result;
  }

  public function tips($long = FALSE) {
    return $long
      ? $this->t('<p>You can embed diagrams with <code>&lt;drupal-diagram data-diagram-uuid="…"&gt;&lt;/drupal-diagram&gt;</code>. Add <code>data-align="left"</code>, <code>"center"</code> or <code>"right"</code> to align it. The embed shows the published version of the diagram.</p>')
      : $this->t('You can embed diagrams (using the <code>&lt;drupal-diagram&gt;</code> tag).');
  }

  /**
   * The render array of one embed.
   */
  protected function buildEmbed(string $uuid, ?string $align): array {
    $build = [
      '#theme' => 'divengine_diagram_embed',
      '#align' => $align,
      '#attached' => ['library' => ['divengine_diagram_embed/embed']],
    ];
    $cache = (new CacheableMetadata())->addCacheTags(['diagram_list']);
    $diagram = $uuid === '' ? NULL : $this->loadDiagram($uuid);
    if (!$diagram) {
      $build['#state'] = 'missing';
      $cache->applyTo($build);
      return $build;
    }
    $cache = (new CacheableMetadata())->addCacheableDependency($diagram);
    $access = $diagram->access('view', NULL, TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      $build['#state'] = 'restricted';
      $cache->applyTo($build);
      return $build;
    }
    $build['#label'] = $diagram->label();
    $build['#url'] = $diagram->toUrl()->toString();
    $build['#state'] = 'no_preview';
    if ($diagram->getPreviewSvg() !== '') {
      $build['#state'] = 'ready';
      $build['#preview_url'] = Url::fromRoute('divengine_modeler.preview', [
        'diagram' => $diagram->id(),
        'file' => $diagram->getPreviewHash() . '.svg',
      ], ['query' => ['fit' => 1]])->toString();
      $build['#dimensions'] = PreviewBuilder::dimensions($diagram->getPreviewSvg());
    }
    $cache->applyTo($build);
    return $build;
  }

  /**
   * The default revision of the diagram with this UUID.
   */
  protected function loadDiagram(string $uuid): ?DiagramInterface {
    $diagrams = $this->entityTypeManager->getStorage('diagram')->loadByProperties(['uuid' => $uuid]);
    $diagram = reset($diagrams);
    return $diagram instanceof DiagramInterface ? $diagram : NULL;
  }

  /**
   * The alignment from data-align, or from the class filter_align leaves.
   */
  protected function alignment(\DOMElement $element): ?string {
    $align = $element->getAttribute('data-align');
    if ($align === '' && preg_match('/(?:^|\s)align-(left|center|right)(?:\s|$)/', $element->getAttribute('class'), $match)) {
      $align = $match[1];
    }
    return in_array($align, static::ALIGNMENTS, TRUE) ? $align : NULL;
  }

  /**
   * Replaces the <drupal-diagram> element with rendered markup.
   */
  protected function replace(\DOMElement $element, string $markup): void {
    $document = $element->ownerDocument;
    if ($markup !== '') {
      foreach (Html::load($markup)->getElementsByTagName('body')->item(0)->childNodes as $node) {
        $element->parentNode->insertBefore($document->importNode($node, TRUE), $element);
      }
    }
    $element->parentNode->removeChild($element);
  }

}
