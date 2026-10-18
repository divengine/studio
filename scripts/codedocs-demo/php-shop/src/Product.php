<?php

declare(strict_types=1);

namespace StudioDemo\Shop;

/** A catalog item with a price expressed in integer cents. */
final class Product {
  public function __construct(public readonly string $sku, public readonly string $name, public readonly int $priceCents) {}

  /** Return the price for a requested quantity.
   * @param int $quantity Number of items, at least one.
   * @return int Total price in cents.
   */
  public function subtotal(int $quantity): int {
    if ($quantity < 1) { throw new \InvalidArgumentException('Quantity must be positive.'); }
    return $this->priceCents * $quantity;
  }
}
