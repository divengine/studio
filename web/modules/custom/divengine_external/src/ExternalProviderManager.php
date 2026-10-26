<?php

declare(strict_types=1);

namespace Drupal\divengine_external;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\divengine_external\Attribute\ExternalProvider;

/**
 * Discovers providers in Plugin/ExternalProvider of any module.
 */
class ExternalProviderManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ExternalProvider', $namespaces, $module_handler, ExternalProviderInterface::class, ExternalProvider::class);
    $this->alterInfo('divengine_external_provider_info');
    $this->setCacheBackend($cache_backend, 'divengine_external_provider_plugins');
  }

  /**
   * The provider that reads links of a host: the one that claims it, or the
   * fallback.
   */
  public function forHost(string $host): ExternalProviderInterface {
    $fallback = NULL;
    foreach ($this->getDefinitions() as $id => $definition) {
      if (in_array($host, $definition['hosts'], TRUE)) {
        return $this->createInstance($id);
      }
      if (!$definition['hosts']) {
        $fallback = $id;
      }
    }
    if ($fallback === NULL) {
      throw new InvalidExternalUrlException('Links to this host are not supported.');
    }
    return $this->createInstance($fallback);
  }

  /**
   * The label of a kind of work, e.g. "GitHub pull request".
   */
  public function kindLabel(string $provider, string $kind): string {
    if (!$this->hasDefinition($provider)) {
      return $kind;
    }
    $label = $this->createInstance($provider)->kinds()[$kind] ?? NULL;
    return $label === NULL ? $kind : (string) $label;
  }

}
