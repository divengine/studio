package com.example.shop.model;

import java.util.Objects;

/**
 * Someone who buys from the shop.
 *
 * <p>Customers are identified by their email, which is unique.</p>
 *
 * @since 1.0
 */
public class Customer {

    /** Internal identifier. */
    private final long id;

    /** Unique email, used to log in. */
    private String email;

    private String fullName;

    /**
     * Creates a customer.
     *
     * @param id the internal identifier
     * @param email the unique email
     * @param fullName the name shown on invoices
     */
    public Customer(long id, String email, String fullName) {
        this.id = id;
        this.email = Objects.requireNonNull(email);
        this.fullName = fullName;
    }

    /** @return the internal identifier */
    public long getId() {
        return id;
    }

    /** @return the unique email */
    public String getEmail() {
        return email;
    }

    public String getFullName() {
        return fullName;
    }

    /**
     * Changes the email.
     *
     * @param email the new email, unique among customers
     * @throws IllegalArgumentException when the email is blank
     */
    public void changeEmail(String email) {
        if (email == null || email.isBlank()) {
            throw new IllegalArgumentException("email");
        }
        this.email = email;
    }
}
