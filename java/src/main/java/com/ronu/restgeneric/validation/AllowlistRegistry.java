package com.ronu.restgeneric.validation;

import com.ronu.restgeneric.annotation.AllowedOrderBy;
import com.ronu.restgeneric.annotation.AllowedRelations;
import org.springframework.stereotype.Component;

import java.util.*;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Per-aggregate registry of allowed relation paths and orderby paths.
 * Populated at startup from {@link AllowedRelations} and {@link AllowedOrderBy} annotations,
 * and can be extended programmatically by child services.
 */
@Component
public class AllowlistRegistry {

    private final Map<Class<?>, Set<String>> relationAllowlist = new ConcurrentHashMap<>();
    private final Map<Class<?>, Set<String>> orderByAllowlist = new ConcurrentHashMap<>();

    /**
     * Reads annotations from the given entity class and registers its allowlists.
     */
    public void register(Class<?> entityClass) {
        AllowedRelations rel = entityClass.getAnnotation(AllowedRelations.class);
        if (rel != null) {
            relationAllowlist.put(entityClass, Set.of(rel.value()));
        }

        AllowedOrderBy ord = entityClass.getAnnotation(AllowedOrderBy.class);
        if (ord != null) {
            orderByAllowlist.put(entityClass, Set.of(ord.value()));
        }
    }

    /**
     * Programmatic override — useful in tests or dynamic configuration.
     */
    public void registerRelations(Class<?> entityClass, Set<String> relations) {
        relationAllowlist.put(entityClass, Collections.unmodifiableSet(new HashSet<>(relations)));
    }

    public void registerOrderBy(Class<?> entityClass, Set<String> paths) {
        orderByAllowlist.put(entityClass, Collections.unmodifiableSet(new HashSet<>(paths)));
    }

    /**
     * Returns allowed relation paths for the entity, or empty set if none declared.
     */
    public Set<String> getAllowedRelations(Class<?> entityClass) {
        return relationAllowlist.getOrDefault(entityClass, Set.of());
    }

    /**
     * Returns allowed orderby paths for the entity.
     * If {@link AllowedOrderBy} is not present, only local (non-dot) paths are allowed.
     */
    public Set<String> getAllowedOrderBy(Class<?> entityClass) {
        return orderByAllowlist.getOrDefault(entityClass, Set.of());
    }

    public boolean isRelationAllowed(Class<?> entityClass, String path) {
        return getAllowedRelations(entityClass).contains(path);
    }

    /**
     * Checks whether the top-level relation segment in the path is declared in RELATIONS.
     * For "manager.department", checks "manager".
     */
    public boolean isRelationRootAllowed(Class<?> entityClass, String path) {
        String root = path.contains(".") ? path.substring(0, path.indexOf('.')) : path;
        return isRelationAllowed(entityClass, path) || isRelationAllowed(entityClass, root);
    }

    public boolean isOrderByAllowed(Class<?> entityClass, String path) {
        Set<String> allowed = getAllowedOrderBy(entityClass);
        if (allowed.isEmpty()) {
            // No annotation present — only allow local fields (no dot notation)
            return !path.contains(".");
        }
        return allowed.contains(path);
    }
}
