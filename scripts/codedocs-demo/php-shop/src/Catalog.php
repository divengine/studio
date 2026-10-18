<?php

declare(strict_types=1);

namespace StudioDemo\Shop;

/** The catalog contract shared by checkout and inventory. */
interface Catalog {
  /** Find a product by its stock keeping unit.
   * @param string $sku Stable product identifier.
   * @return Product|null The product, or null when it is not listed.
   */
  public function find(string $sku): ?Product;
}
