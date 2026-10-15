<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\divengine_modeler\DiagramBacklinks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the diagrams with elements linked to the entity of the current page.
 */
#[Block(
  id: 'divengine_diagram_backlinks',
  admin_label: new TranslatableMarkup('Diagrams referencing this record'),
  category: new TranslatableMarkup('Divengine'),
)]
class DiagramBacklinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RouteMatchInterface $routeMatch,
    protected DiagramBacklinks $backlinks,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('divengine_modeler.backlinks'),
    );
  }

  public function build(): array {
    $entity = $this->routeEntity();
    $build = $entity ? $this->backlinks->build($entity) : [];
    $build['#cache']['contexts'] = Cache::mergeContexts($build['#cache']['contexts'] ?? [], ['route']);
    return $build;
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

  /**
   * The content entity the current route is about, if any.
   */
  protected function routeEntity(): ?ContentEntityInterface {
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof ContentEntityInterface) {
        return $parameter;
      }
    }
    return NULL;
  }

}
