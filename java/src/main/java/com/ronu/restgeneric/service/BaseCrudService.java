package com.ronu.restgeneric.service;

import com.ronu.restgeneric.query.FilterCompiler;
import com.ronu.restgeneric.query.OrderByCompiler;
import com.ronu.restgeneric.query.QueryPlanCompiler;
import jakarta.persistence.EntityManager;
import jakarta.persistence.EntityNotFoundException;
import org.springframework.transaction.annotation.Transactional;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Extends {@link BaseQueryService} with full CRUD and bulk write operations.
 *
 * <p>All write operations use the injected {@code jakarta.persistence.EntityManager} directly
 * and are wrapped in transactions. Cache invalidation is handled by Spring's {@code @CacheEvict}
 * or can be overridden per subclass.
 *
 * @param <E>  JPA entity type
 * @param <V>  View / DTO type
 * @param <ID> Primary key type
 */
public abstract class BaseCrudService<E, V, ID> extends BaseQueryService<E, V> {

    protected BaseCrudService(EntityManager em,
                               QueryPlanCompiler compiler,
                               FilterCompiler filterCompiler,
                               OrderByCompiler orderByCompiler) {
        super(em, compiler, filterCompiler, orderByCompiler);
    }

    // -------------------------------------------------------------------------
    // CRUD operations
    // -------------------------------------------------------------------------

    @Transactional
    public V create(Map<String, Object> data) {
        E entity = newInstance();
        mapData(entity, data);
        em.persist(entity);
        em.flush();
        em.refresh(entity);
        return toView(entity);
    }

    @Transactional
    public V update(ID id, Map<String, Object> data) {
        E entity = em.find(entityClass(), id);
        if (entity == null) throw entityNotFound(id);
        mapData(entity, data);
        em.flush();
        return toView(entity);
    }

    @Transactional
    public List<V> bulkCreate(List<Map<String, Object>> items) {
        List<V> results = new ArrayList<>();
        for (Map<String, Object> data : items) {
            results.add(create(data));
        }
        return results;
    }

    @Transactional
    public List<V> bulkUpdate(List<Map<String, Object>> items) {
        List<V> results = new ArrayList<>();
        for (Map<String, Object> data : items) {
            @SuppressWarnings("unchecked")
            ID id = (ID) data.get("id");
            if (id == null) throw new IllegalArgumentException("Each bulk update item must include 'id'.");
            results.add(update(id, data));
        }
        return results;
    }

    @Transactional
    public void delete(ID id) {
        E entity = em.find(entityClass(), id);
        if (entity == null) throw entityNotFound(id);
        em.remove(entity);
    }

    @Transactional
    public void bulkDelete(List<ID> ids) {
        for (ID id : ids) {
            delete(id);
        }
    }

    // -------------------------------------------------------------------------
    // Extension points — override in subclasses
    // -------------------------------------------------------------------------

    /**
     * Creates a new blank entity instance. Override for custom factory logic.
     */
    protected E newInstance() {
        try {
            return entityClass().getDeclaredConstructor().newInstance();
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException("Cannot instantiate " + entityClass().getName(), e);
        }
    }

    /**
     * Maps raw data map onto entity fields using reflection.
     * Override for custom mapping (MapStruct, ModelMapper, etc.).
     */
    protected void mapData(E entity, Map<String, Object> data) {
        data.forEach((key, value) -> {
            try {
                var field = findField(entity.getClass(), key);
                if (field != null) {
                    field.setAccessible(true);
                    field.set(entity, value);
                }
            } catch (IllegalAccessException ignored) {
            }
        });
    }

    private java.lang.reflect.Field findField(Class<?> clazz, String name) {
        while (clazz != null && clazz != Object.class) {
            for (var field : clazz.getDeclaredFields()) {
                if (field.getName().equals(name)) return field;
            }
            clazz = clazz.getSuperclass();
        }
        return null;
    }

    private EntityNotFoundException entityNotFound(ID id) {
        return new EntityNotFoundException(entityClass().getSimpleName() + " with id=" + id + " not found.");
    }
}
