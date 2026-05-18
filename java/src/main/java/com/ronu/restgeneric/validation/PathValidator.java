package com.ronu.restgeneric.validation;

import com.ronu.restgeneric.exception.InvalidRelationException;
import com.ronu.restgeneric.exception.PathDepthExceededException;
import jakarta.persistence.EntityManagerFactory;
import jakarta.persistence.metamodel.*;
import org.springframework.stereotype.Component;

import java.util.*;

/**
 * Validates relation and orderby paths against the allowlist and the JPA metamodel.
 * Also determines whether a path ends in a to-many relation (collection).
 */
@Component
public class PathValidator {

    private final AllowlistRegistry registry;
    private final EntityManagerFactory emf;
    private final int maxDepth;

    public PathValidator(AllowlistRegistry registry, EntityManagerFactory emf) {
        this(registry, emf, 5);
    }

    public PathValidator(AllowlistRegistry registry, EntityManagerFactory emf, int maxDepth) {
        this.registry = registry;
        this.emf = emf;
        this.maxDepth = maxDepth;
    }

    /**
     * Validates a relation path and returns whether its leaf is a to-many attribute.
     *
     * @throws InvalidRelationException   if the path is not in the allowlist
     * @throws PathDepthExceededException if the path exceeds maxDepth segments
     */
    public boolean validateRelationPath(Class<?> rootEntity, String path) {
        String[] segments = path.split("\\.");
        if (segments.length > maxDepth) {
            throw new PathDepthExceededException(maxDepth, path);
        }

        if (!registry.isRelationAllowed(rootEntity, path)) {
            Set<String> allowed = registry.getAllowedRelations(rootEntity);
            throw new InvalidRelationException(
                    "Relation '" + path + "' is not allowed on " + rootEntity.getSimpleName()
                    + ". Allowed: " + (allowed.isEmpty() ? "(none)" : String.join(", ", allowed)));
        }

        return isToManyPath(rootEntity, segments);
    }

    /**
     * Validates an orderby path against the allowlist.
     *
     * @throws InvalidRelationException if the path is not allowed
     */
    public void validateOrderByPath(Class<?> rootEntity, String path) {
        if (!registry.isOrderByAllowed(rootEntity, path)) {
            Set<String> allowed = registry.getAllowedOrderBy(rootEntity);
            throw new InvalidRelationException(
                    "OrderBy path '" + path + "' is not allowed on " + rootEntity.getSimpleName()
                    + ". Allowed: " + (allowed.isEmpty() ? "(local fields only)" : String.join(", ", allowed)));
        }
    }

    /**
     * Walks the JPA metamodel along the dot-separated segments to determine
     * whether the path ends in a collection (to-many) or not (to-one / scalar).
     */
    public boolean isToManyPath(Class<?> rootEntity, String[] segments) {
        Metamodel metamodel = emf.getMetamodel();
        ManagedType<?> current = metamodel.managedType(rootEntity);

        for (String segment : segments) {
            Attribute<?, ?> attr = getAttribute(current, segment);
            if (attr == null) return false; // unknown — treat as to-one for safety

            if (attr instanceof PluralAttribute) {
                return true; // to-many found at or before leaf
            }
            if (attr instanceof SingularAttribute<?, ?> singular) {
                Class<?> type = singular.getType().getJavaType();
                try {
                    current = metamodel.managedType(type);
                } catch (IllegalArgumentException e) {
                    return false; // leaf is a basic type
                }
            }
        }
        return false;
    }

    private Attribute<?, ?> getAttribute(ManagedType<?> type, String name) {
        try {
            return type.getAttribute(name);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }
}
