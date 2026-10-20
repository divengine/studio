package com.example.shop.service;

import com.example.shop.model.Customer;
import com.example.shop.model.Order;
import com.example.shop.model.OrderLine;
import com.example.shop.repository.OrderRepository;
import java.time.Clock;
import java.util.List;

/**
 * The order service of the shop, backed by an {@link OrderRepository}.
 */
public class DefaultOrderService implements OrderService {

    private final OrderRepository orders;
    private final Clock clock;
    private long nextId = 1;

    /**
     * @param orders where orders are stored
     * @param clock the time source, so tests can fix it
     */
    public DefaultOrderService(OrderRepository orders, Clock clock) {
        this.orders = orders;
        this.clock = clock;
    }

    @Override
    @Audited("order.place")
    public Order place(long customerId, List<OrderLine> lines) {
        if (lines.isEmpty()) {
            throw new IllegalArgumentException("An order needs at least one line");
        }
        Order order = new Order(nextId++, new Customer(customerId, "unknown@example.com", null), clock.instant());
        lines.forEach(order::addLine);
        return orders.save(order);
    }

    @Override
    @Audited("order.pay")
    public void markPaid(long orderId) {
        orders.findById(orderId).orElseThrow(() -> new OrderNotFoundException(orderId));
    }

    @Override
    @Audited("order.cancel")
    public void cancel(long orderId, String reason) {
        orders.findById(orderId).orElseThrow(() -> new OrderNotFoundException(orderId));
    }

    /**
     * @deprecated use {@link #cancel(long, String)} with a reason.
     * @param orderId the order
     */
    @Deprecated
    public void cancel(long orderId) {
        cancel(orderId, "");
    }
}
