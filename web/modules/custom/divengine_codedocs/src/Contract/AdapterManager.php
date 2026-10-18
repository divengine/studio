<?php

declare(strict_types=1);

namespace Drupal\divengine_codedocs\Contract;

use Drupal\divengine_codedocs\Contract\Adapter\CodeDocsAdapterInterface;

/** Registry of extractor formats, independent of programming language. */
class AdapterManager {

  protected array $adapters = [];

  public function addAdapter(CodeDocsAdapterInterface $adapter): void {
    $this->adapters[$adapter->id()] = $adapter;
  }

  public function resolve(array $document, string $format = 'auto'): CodeDocsAdapterInterface {
    if ($format !== 'auto') {
      return $this->adapters[$format] ?? throw new ContractException('Unknown documentation format: ' . $format);
    }
    foreach ($this->adapters as $adapter) {
      if ($adapter->applies($document)) {
        return $adapter;
      }
    }
    throw new ContractException('Unrecognized documentation. Send the Divengine contract, Javadoc JSON or TypeScript JSON.');
  }

}
