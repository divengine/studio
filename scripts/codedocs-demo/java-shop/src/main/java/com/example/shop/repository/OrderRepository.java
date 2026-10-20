package com.example.shop.repository;

import com.example.shop.model.Order;
import com.example.shop.model.OrderStatus;
import java.util.List;

/**
 * Orders, with the queries the shop needs.
 */
public interface OrderRepository extends Repository<Order, Long> {

    /**
     * Orders of a customer, newest first.
     *
     * @param customerId the customer
     * @return their orders
     */
    List<Order> findByCustomer(long customerId);

    /**
     * Orders in a status.
     *
     * @param status the status
     * @return the orders, oldest first
     */
    List<Order> findByStatus(OrderStatus status);
}
