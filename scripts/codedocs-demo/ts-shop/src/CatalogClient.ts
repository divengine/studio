import type { Product } from './Product';
import { apiClient } from './HttpClient';

/** Load the catalog from the backend.
 * @returns Products currently available to order.
 */
export async function loadProducts(): Promise<Product[]> {
  return apiClient.get<Product[]>('/api/products');
}

/** Send an order to the checkout endpoint.
 * @param sku Product identifier.
 * @param quantity Number of items to purchase.
 * @returns The server-generated order identifier.
 */
export async function placeOrder(sku: string, quantity = 1): Promise<{ orderId: string }> {
  return apiClient.post<{ orderId: string }>('/api/orders', { sku, quantity });
}
