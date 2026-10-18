<?php

declare(strict_types=1);

namespace StudioDemo\Shop;

/** Calculates a checkout total without floating point rounding. */
final class Checkout {
  /** Build checkout with its product catalog.
   * @param Catalog $catalog Source of product prices.
   */
  public function __construct(private readonly Catalog $catalog) {}

  /** Price an order line using the current catalog.
   * @param string $sku Product identifier.
   * @param int $quantity Number of items.
   * @return int Amount to charge, in cents.
   * @throws \InvalidArgumentException When the product is unknown.
   */
  public function total(string $sku, int $quantity = 1): int {
    $product = $this->catalog->find($sku);
    if (!$product) { throw new \InvalidArgumentException('Unknown product: ' . $sku); }
    return $product->subtotal($quantity);
  }
}
