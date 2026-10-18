<?php

declare(strict_types=1);

namespace Drupal\divengine_database\Contract;

use Drupal\divengine_database\Contract\Adapter\ContractAdapterInterface;

/**
 * The contract adapters: one per analyzer format.
 */
class AdapterManager {

  /**
   * @var \Drupal\divengine_database\Contract\Adapter\ContractAdapterInterface[]
   */
  protected array $adapters = [];

  public function addAdapter(ContractAdapterInterface $adapter): void {
    $this->adapters[$adapter->id()] = $adapter;
  }

  /**
   * Labels by id, for choosers.
   */
  public function options(): array {
    return array_map(fn (ContractAdapterInterface $adapter) => $adapter->label(), $this->adapters);
  }

  /**
   * The adapter with that id, or the first that recognizes the content.
   */
  public function resolve(string $content, string $id = 'auto', string $filename = ''): ContractAdapterInterface {
    if ($id !== 'auto' && $id !== '') {
      return $this->adapters[$id] ?? throw new ContractException(sprintf('Unknown format "%s". Known: %s.', $id, implode(', ', array_keys($this->adapters))));
    }
    foreach ($this->adapters as $adapter) {
      if ($adapter->applies($content, $filename)) {
        return $adapter;
      }
    }
    throw new ContractException(sprintf('Unrecognized file. Send the Divengine contract (JSON) or one of: %s.', implode(', ', $this->options())));
  }

  /**
   * The contract payload of what an analyzer produced.
   */
  public function toContract(string $content, string $id = 'auto', string $filename = ''): array {
    return $this->resolve($content, $id, $filename)->toContract($content);
  }

}
