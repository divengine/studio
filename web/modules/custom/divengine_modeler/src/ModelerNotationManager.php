<?php

declare(strict_types=1);

namespace Drupal\divengine_modeler;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\divengine_modeler\Attribute\ModelerNotation;

/**
 * Discovers ModelerNotation plugins.
 */
class ModelerNotationManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ModelerNotation', $namespaces, $module_handler, ModelerNotationInterface::class, ModelerNotation::class);
    $this->setCacheBackend($cache_backend, 'divengine_modeler_notations');
    $this->alterInfo('modeler_notation');
  }

  /**
   * Notation labels keyed by plugin ID.
   */
  public function getOptions(): array {
    return array_map(static fn (array $definition): string => (string) $definition['label'], $this->getDefinitions());
  }

}
