package com.example.shop.model;

import java.math.BigDecimal;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;

/**
 * A customer's order: lines, a status and the moment it was placed.
 *
 * @see OrderStatus
 */
public class Order {

    private final long id;
    private final Customer customer;
    private final List<OrderLine> lines = new ArrayList<>();
    private OrderStatus status = OrderStatus.NEW;
    private final Instant placedAt;

    /**
     * Places a new order.
     *
     * @param id the order number
     * @param customer who orders
     * @param placedAt when
     */
    public Order(long id, Customer customer, Instant placedAt) {
        this.id = id;
        this.customer = customer;
        this.placedAt = placedAt;
    }

    public long getId() {
        return id;
    }

    /** @return who placed the order */
    public Customer getCustomer() {
        return customer;
    }

    /** @return the lines, in the order they were added */
    public List<OrderLine> getLines() {
        return List.copyOf(lines);
    }

    /**
     * Adds a line.
     *
     * @param line the product, quantity and price
     * @throws IllegalStateException when the order is not {@link OrderStatus#NEW} anymore
     */
    public void addLine(OrderLine line) {
        if (status != OrderStatus.NEW) {
            throw new IllegalStateException("Only new orders can change");
        }
        lines.add(line);
    }

    /** @return the sum of the lines */
    public BigDecimal total() {
        return lines.stream().map(OrderLine::total).reduce(BigDecimal.ZERO, BigDecimal::add);
    }

    public OrderStatus getStatus() {
        return status;
    }

    public Instant getPlacedAt() {
        return placedAt;
    }
}
