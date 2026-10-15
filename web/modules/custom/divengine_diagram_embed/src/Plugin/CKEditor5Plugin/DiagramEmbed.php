<?php

declare(strict_types=1);

namespace Drupal\divengine_diagram_embed\Plugin\CKEditor5Plugin;

use Drupal\ckeditor5\Plugin\CKEditor5PluginDefault;
use Drupal\Core\Url;
use Drupal\editor\EditorInterface;

/**
 * Gives the diagram button the URLs of its endpoints.
 */
class DiagramEmbed extends CKEditor5PluginDefault {

  public function getDynamicPluginConfig(array $static_plugin_config, EditorInterface $editor): array {
    return [
      'divengineDiagram' => [
        'searchUrl' => Url::fromRoute('divengine_diagram_embed.search')->toString(TRUE)->getGeneratedUrl(),
        'lookupUrl' => Url::fromRoute('divengine_diagram_embed.lookup')->toString(TRUE)->getGeneratedUrl(),
      ],
    ] + $static_plugin_config;
  }

}
