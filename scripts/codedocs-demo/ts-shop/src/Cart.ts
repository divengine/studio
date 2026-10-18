import type { CartLine } from './Product';

/** Calculate an order total using integer cents.
 * @param lines Products and quantities in the basket.
 * @returns Amount to display before checkout.
 */
export function cartTotal(lines: CartLine[]): number {
  return lines.reduce((sum, line) => sum + line.product.priceCents * line.quantity, 0);
}

/** Reject a basket containing invalid quantities.
 * @param lines Basket to inspect before submitting.
 */
export function validateCart(lines: CartLine[]): boolean {
  return lines.every(line => Number.isInteger(line.quantity) && line.quantity > 0);
}
