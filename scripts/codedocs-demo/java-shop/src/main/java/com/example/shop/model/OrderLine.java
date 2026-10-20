package com.example.shop.model;

import java.math.BigDecimal;

/**
 * One product in an order, with its quantity and the price it was sold at.
 *
 * @param productId the product sold
 * @param quantity how many units, at least one
 * @param unitPrice the price of one unit when the order was placed
 */
public record OrderLine(long productId, int quantity, BigDecimal unitPrice) {

    /**
     * The total of the line.
     *
     * @return quantity times unit price
     */
    public BigDecimal total() {
        return unitPrice.multiply(BigDecimal.valueOf(quantity));
    }
}
