package com.example.shop.repository;

import java.util.List;
import java.util.Optional;

/**
 * Storage of one kind of entity.
 *
 * @param <T> the entity
 * @param <ID> its identifier
 */
public interface Repository<T, ID> {

    /**
     * Finds an entity.
     *
     * @param id its identifier
     * @return the entity, or empty when there is none
     */
    Optional<T> findById(ID id);

    /** @return every entity, unordered */
    List<T> findAll();

    /**
     * Stores an entity, new or changed.
     *
     * @param entity what to store
     * @return the stored entity
     */
    T save(T entity);
}
