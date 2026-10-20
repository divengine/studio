package com.example.shop.model;

/**
 * Where an order is in its life.
 */
public enum OrderStatus {
    /** Placed, not paid yet. */
    NEW,
    /** Paid and waiting to be shipped. */
    PAID,
    /** On its way to the customer. */
    SHIPPED,
    /** Cancelled before shipping. */
    CANCELLED
}
