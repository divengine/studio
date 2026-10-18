<?php

declare(strict_types=1);

namespace Drupal\divengine_process\Readiness;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\divengine_process\Attribute\ReadinessCriterion;

/**
 * Discovers readiness criteria in Plugin/ReadinessCriterion of any module.
 */
class ReadinessCriterionManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ReadinessCriterion', $namespaces, $module_handler, ReadinessCriterionInterface::class, ReadinessCriterion::class);
    $this->alterInfo('divengine_readiness_criterion_info');
    $this->setCacheBackend($cache_backend, 'divengine_readiness_criterion_plugins');
  }

  /**
   * Criterion labels keyed by code, sorted by label.
   *
   * @return array<string, string>
   */
  public function options(): array {
    $options = array_map(fn (array $definition) => (string) $definition['label'], $this->getDefinitions());
    asort($options);
    return $options;
  }

}
