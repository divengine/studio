<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Url;
use Drupal\divengine_modeler\Entity\DiagramInterface;

/**
 * Renders a diagram: preview, linked elements and, for text notations, the
 * source.
 */
class DiagramViewBuilder extends EntityViewBuilder {

  public function buildComponents(array &$build, array $entities, array $displays, $view_mode): void {
    parent::buildComponents($build, $entities, $displays, $view_mode);
    foreach ($entities as $id => $diagram) {
      /** @var \Drupal\divengine_modeler\Entity\DiagramInterface $diagram */
      $build[$id]['divengine_preview'] = $this->buildPreview($diagram) + ['#weight' => -5];
      if ($view_mode === 'full') {
        $build[$id]['divengine_links'] = $this->buildLinks($diagram) + ['#weight' => 10];
        if ($diagram->getNotation()->family() === ModelerNotationInterface::FAMILY_TEXT) {
          $build[$id]['divengine_source'] = [
            '#type' => 'details',
            '#title' => $this->t('Source'),
            '#weight' => 20,
            'code' => [
              '#type' => 'html_tag',
              '#tag' => 'pre',
              '#value' => $diagram->getSource(),
              '#attributes' => ['class' => ['divengine-diagram-source']],
            ],
          ];
        }
      }
    }
  }

  /**
   * The stored preview, or an off-screen render for editors when it is stale.
   */
  protected function buildPreview(DiagramInterface $diagram): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['divengine-diagram-preview']],
      '#attached' => ['library' => ['divengine_modeler/diagram']],
      '#cache' => ['contexts' => ['user.permissions'], 'tags' => $diagram->getCacheTags()],
    ];
    if ($diagram->getPreviewSvg() !== '') {
      $build['image'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => Url::fromRoute('divengine_modeler.preview', [
            'diagram' => $diagram->id(),
            'file' => $diagram->getPreviewHash() . '.svg',
          ])->toString(),
          'alt' => $this->t('Diagram @label', ['@label' => $diagram->label()]),
          'loading' => 'lazy',
        ],
      ];
      $build['open'] = [
        '#type' => 'link',
        '#title' => $this->t('Open the preview with clickable links'),
        '#url' => Url::fromRoute('divengine_modeler.preview', [
          'diagram' => $diagram->id(),
          'file' => $diagram->getPreviewHash() . '.svg',
        ]),
        '#attributes' => ['class' => ['divengine-diagram-preview__open'], 'target' => '_blank'],
      ];
    }
    if (!$diagram->hasFreshPreview()) {
      $build['#cache']['contexts'][] = 'user';
      if ($diagram->isDefaultRevision() && $diagram->access('update')) {
        // The first editor to see the diagram renders and stores its preview.
        $notation = $diagram->getNotation();
        $build['#attributes']['data-divengine-preview-render'] = $notation->getPluginId();
        $build['#attached']['library'][] = $notation->previewLibrary();
        $build['#attached']['drupalSettings']['divengineModeler']['previews'][$diagram->id()] = [
          'notation' => $notation->getPluginId(),
          'source' => $diagram->getSource(),
          'bindings' => $diagram->getBindings() ?: new \stdClass(),
          'hash' => $diagram::hashSource($diagram->getSource()),
          'storeUrl' => Url::fromRoute('divengine_modeler.store_preview', ['diagram' => $diagram->id()])->toString(),
          'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
          'settings' => $notation->clientSettings($diagram),
        ];
        $build['#attributes']['data-diagram-id'] = $diagram->id();
      }
      $build['status'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $diagram->getPreviewSvg() === ''
          ? $this->t('This diagram has no preview yet.')
          : $this->t('The preview is older than the source.'),
        '#attributes' => ['class' => ['divengine-diagram-preview__status']],
      ];
    }
    return $build;
  }

  /**
   * The table of linked elements, showing only targets the user can view.
   */
  protected function buildLinks(DiagramInterface $diagram): array {
    $rows = [];
    $tags = $diagram->getCacheTags();
    $resolver = \Drupal::service('divengine_modeler.entity_resolver');
    $can_trace = \Drupal::moduleHandler()->moduleExists('divengine_traceability')
      && \Drupal::entityTypeManager()->getAccessControlHandler('trace_link')->createAccess();
    foreach (\Drupal::service('divengine_modeler.link_index')->linksOf((int) $diagram->id()) as $link) {
      $uri = 'entity:' . $link->target_type . '/' . $link->target_id;
      $target = $resolver->load($uri);
      if ($target) {
        $tags = array_merge($tags, $target->getCacheTags());
      }
      if ($link->broken || !$target) {
        $cell = ['#markup' => $this->t('Missing target (@uri)', ['@uri' => $uri])];
      }
      elseif (!$target->access('view')) {
        $cell = ['#markup' => $this->t('Restricted')];
      }
      else {
        $cell = $target->hasLinkTemplate('canonical') ? $target->toLink()->toRenderable() : ['#plain_text' => $target->label()];
      }
      $actions = [];
      if ($can_trace && $target && $target->access('view')) {
        $actions = [
          '#type' => 'link',
          '#title' => $this->t('Record a trace'),
          '#url' => Url::fromRoute('entity.trace_link.add_form', [], [
            'query' => ['source' => 'diagram/' . $diagram->id(), 'target' => $link->target_type . '/' . $link->target_id],
          ]),
        ];
      }
      $rows[] = [
        ['data' => ['#plain_text' => $link->element_id]],
        ['data' => $cell],
        ['data' => $actions],
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Linked elements (@count)', ['@count' => count($rows)]),
      '#open' => (bool) $rows,
      '#cache' => ['contexts' => ['user'], 'tags' => array_values(array_unique($tags))],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Element'), $this->t('Linked to'), ''],
        '#rows' => $rows,
        '#empty' => $this->t('No element of this diagram is linked to a record.'),
      ],
    ];
  }

}
