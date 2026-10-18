/** A catalog item returned by the shop API. */
export interface Product {
  /** Stable stock keeping unit. */
  sku: string;
  /** Human-readable product name. */
  name: string;
  /** Unit price in integer cents. */
  priceCents: number;
}

/** An order line before checkout. */
export interface CartLine {
  /** The selected catalog item. */
  product: Product;
  /** Number of items to buy. */
  quantity: number;
}
