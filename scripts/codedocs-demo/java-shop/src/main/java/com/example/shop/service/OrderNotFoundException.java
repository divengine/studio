package com.example.shop.service;

/**
 * Thrown when an order number does not exist.
 */
public class OrderNotFoundException extends RuntimeException {

    /**
     * @param id the missing order number
     */
    public OrderNotFoundException(long id) {
        super("No order " + id);
    }
}
