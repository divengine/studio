package com.example.shop.service;

import com.example.shop.model.Order;
import com.example.shop.model.OrderLine;
import java.util.List;

/**
 * What the shop can do with orders.
 *
 * <p>Implemented by {@link DefaultOrderService}.</p>
 */
public interface OrderService {

    /**
     * Places an order for a customer.
     *
     * @param customerId who orders
     * @param lines what they order, at least one line
     * @return the new order, in status {@link com.example.shop.model.OrderStatus#NEW}
     */
    Order place(long customerId, List<OrderLine> lines);

    /**
     * Records the payment of an order.
     *
     * @param orderId the order
     * @throws OrderNotFoundException when there is no such order
     */
    void markPaid(long orderId);

    /**
     * Cancels an order that was not shipped yet.
     *
     * @param orderId the order
     * @param reason why, shown to the customer
     * @throws OrderNotFoundException when there is no such order
     */
    void cancel(long orderId, String reason);
}
