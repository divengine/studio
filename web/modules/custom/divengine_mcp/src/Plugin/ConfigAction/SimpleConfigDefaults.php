<?php

declare(strict_types=1);

namespace Drupal\divengine_mcp\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets simple configuration values only where the site has none yet.
 *
 * Recipes are re-applied on updates (ADR-0015). simpleConfigUpdate would put
 * its values back every time and undo what administrators changed; this
 * action gives a fresh site its defaults, adds keys that a newer recipe
 * introduces, and leaves every existing value alone. Keys may be dotted
 * ("plugins.content") so that a default covers one entry of a mapping.
 */
#[ConfigAction(
  id: 'simpleConfigDefaults',
  admin_label: new TranslatableMarkup('Simple configuration defaults'),
)]
final class SimpleConfigDefaults implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get(ConfigFactoryInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    $config = $this->configFactory->getEditable($configName);
    if ($config->isNew()) {
      throw new ConfigActionException(sprintf('Config %s does not exist so can not be updated', $configName));
    }
    if (!is_array($value)) {
      throw new ConfigActionException(sprintf('Config %s can not be updated because $value is not an array', $configName));
    }
    $changed = FALSE;
    foreach ($value as $key => $default) {
      if ($config->get($key) === NULL) {
        $config->set($key, $default);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $config->save();
    }
  }

}
