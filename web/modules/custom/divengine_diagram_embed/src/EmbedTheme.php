<?php

declare(strict_types=1);

namespace Drupal\divengine_diagram_embed;

use Drupal\Core\Template\Attribute;

/**
 * Prepares variables for divengine-diagram-embed.html.twig.
 */
class EmbedTheme {

  public function preprocess(array &$variables): void {
    $classes = ['divengine-diagram-embed', 'divengine-diagram-embed--' . str_replace('_', '-', $variables['state'])];
    if ($variables['align']) {
      $classes[] = 'divengine-diagram-embed--align-' . $variables['align'];
    }
    $variables['attributes'] = new Attribute(['class' => $classes]);
    $variables['frame_attributes'] = new Attribute();
    if (is_array($variables['dimensions'])) {
      [$width, $height] = $variables['dimensions'];
      $variables['attributes']->setAttribute('style', sprintf('--divengine-diagram-width: %dpx', (int) ceil($width)));
      $variables['frame_attributes']->setAttribute('style', sprintf('--divengine-diagram-ratio: %s / %s', round($width, 2), round($height, 2)));
    }
  }

}
