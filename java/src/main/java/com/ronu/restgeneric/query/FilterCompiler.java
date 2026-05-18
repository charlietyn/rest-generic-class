package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.model.*;
import com.ronu.restgeneric.exception.InvalidFilterException;
import jakarta.persistence.criteria.*;
import org.springframework.data.jpa.domain.Specification;
import org.springframework.stereotype.Component;

import java.util.ArrayList;
import java.util.List;
import java.util.stream.Collectors;

/**
 * Translates a {@link FilterNode} AST into a JPA {@link Specification} using
 * the {@code jakarta.persistence.criteria.*} API — fully compatible with Spring Boot 3.
 *
 * <p>The returned Specification can be used with both standard Spring Data
 * {@code JpaSpecificationExecutor} and Blaze-Persistence's {@code EntityViewSpecificationExecutor}.
 */
@Component
public class FilterCompiler {

    public <E> Specification<E> toSpecification(FilterNode root) {
        if (root == null) return Specification.where(null);
        return (entity, query, cb) -> toPredicate(entity, query, cb, root);
    }

    @SuppressWarnings("unchecked")
    private <E> Predicate toPredicate(Root<E> root, CriteriaQuery<?> query,
                                       CriteriaBuilder cb, FilterNode node) {
        return switch (node) {
            case GroupNode g -> group(root, query, cb, g);
            case ConditionNode c -> condition(root, query, cb, c);
            case RelationFilterNode r -> relationFilter(root, query, cb, r);
        };
    }

    private <E> Predicate group(Root<E> root, CriteriaQuery<?> query,
                                 CriteriaBuilder cb, GroupNode g) {
        List<Predicate> predicates = g.children().stream()
                .map(child -> toPredicate(root, query, cb, child))
                .collect(Collectors.toList());

        Predicate[] arr = predicates.toArray(new Predicate[0]);
        return g.op() == LogicalOp.AND ? cb.and(arr) : cb.or(arr);
    }

    @SuppressWarnings({"unchecked", "rawtypes"})
    private <E> Predicate condition(Root<E> root, CriteriaQuery<?> query,
                                     CriteriaBuilder cb, ConditionNode c) {
        Path<?> path = resolvePath(root, c.path());
        String raw = c.values().isEmpty() ? "" : c.values().get(0);

        return switch (c.op()) {
            case EQ, NE_ALT -> cb.equal(path, coerce(raw));
            case NE -> cb.notEqual(path, coerce(raw));
            case GT -> cb.greaterThan((Expression<Comparable>) path, (Comparable) coerce(raw));
            case GE -> cb.greaterThanOrEqualTo((Expression<Comparable>) path, (Comparable) coerce(raw));
            case LT -> cb.lessThan((Expression<Comparable>) path, (Comparable) coerce(raw));
            case LE -> cb.lessThanOrEqualTo((Expression<Comparable>) path, (Comparable) coerce(raw));
            case LIKE, ILIKE -> cb.like(cb.lower((Expression<String>) path), raw.toLowerCase());
            case NOT_LIKE, NOT_ILIKE -> cb.notLike(cb.lower((Expression<String>) path), raw.toLowerCase());
            case IN -> path.in(coerceList(c.values()));
            case NOT_IN -> path.in(coerceList(c.values())).not();
            case BETWEEN -> {
                List<String> vals = c.values();
                yield cb.between((Expression<Comparable>) path,
                        (Comparable) coerce(vals.get(0)), (Comparable) coerce(vals.get(1)));
            }
            case NOT_BETWEEN -> {
                List<String> vals = c.values();
                yield cb.between((Expression<Comparable>) path,
                        (Comparable) coerce(vals.get(0)), (Comparable) coerce(vals.get(1))).not();
            }
            case NULL -> cb.isNull(path);
            case NOT_NULL -> cb.isNotNull(path);
            case EXISTS -> cb.isNotNull(path);
            case NOT_EXISTS -> cb.isNull(path);
            case DATE -> cb.equal(cb.function("DATE", String.class, path), coerce(raw));
            case NOT_DATE -> cb.notEqual(cb.function("DATE", String.class, path), coerce(raw));
            case REGEXP, NOT_REGEXP -> cb.like((Expression<String>) path, raw);
        };
    }

    /**
     * Relation-scoped filter — equivalent to Eloquent's whereHas().
     * Uses a correlated EXISTS subquery so the root query count isn't inflated.
     */
    @SuppressWarnings({"unchecked", "rawtypes"})
    private <E> Predicate relationFilter(Root<E> root, CriteriaQuery<?> query,
                                          CriteriaBuilder cb, RelationFilterNode r) {
        // Build an EXISTS subquery correlated to the root via the relation path
        Subquery<?> sub = query.subquery(Long.class);
        Root<?> subRoot = sub.from(resolveRelationType(root, r.relationPath()));
        sub.select((Expression) cb.literal(1L));

        // Apply inner conditions to the subquery
        Predicate innerPredicate = toPredicate((Root) subRoot, (CriteriaQuery) sub, cb, r.inner());
        // Correlate: subRoot must be reachable from root via the relation join
        Predicate correlate = cb.equal(
                resolvePath(subRoot, resolveJoinKey(root, r.relationPath())),
                resolvePath(root, "id"));
        sub.where(innerPredicate, correlate);

        return cb.exists(sub);
    }

    // -------------------------------------------------------------------------
    // Path resolution helpers
    // -------------------------------------------------------------------------

    private Path<?> resolvePath(Root<?> root, String dotPath) {
        String[] segments = dotPath.split("\\.");
        Path<?> path = root;
        for (String seg : segments) {
            path = path.get(seg);
        }
        return path;
    }

    private Path<?> resolvePath(Path<?> from, String dotPath) {
        String[] segments = dotPath.split("\\.");
        Path<?> path = from;
        for (String seg : segments) {
            path = path.get(seg);
        }
        return path;
    }

    @SuppressWarnings("unchecked")
    private <E> Class<?> resolveRelationType(Root<E> root, String relationPath) {
        String[] segments = relationPath.split("\\.");
        jakarta.persistence.metamodel.Bindable<?> current = root.getModel();
        for (String seg : segments) {
            if (current instanceof jakarta.persistence.metamodel.EntityType<?> et) {
                var attr = et.getAttribute(seg);
                if (attr instanceof jakarta.persistence.metamodel.SingularAttribute<?, ?> sa) {
                    current = (jakarta.persistence.metamodel.Bindable<?>) sa.getType();
                } else if (attr instanceof jakarta.persistence.metamodel.PluralAttribute<?, ?, ?> pa) {
                    current = (jakarta.persistence.metamodel.Bindable<?>) pa.getElementType();
                }
            }
        }
        return ((jakarta.persistence.metamodel.ManagedType<?>) current).getJavaType();
    }

    private String resolveJoinKey(Root<?> root, String relationPath) {
        // Simplified: assumes the join is via the foreign key "id" of the root entity
        // Override in subclasses for custom join keys
        return "id";
    }

    // -------------------------------------------------------------------------
    // Type coercion
    // -------------------------------------------------------------------------

    private Object coerce(String raw) {
        if (raw == null || "null".equalsIgnoreCase(raw)) return null;
        if ("true".equalsIgnoreCase(raw)) return Boolean.TRUE;
        if ("false".equalsIgnoreCase(raw)) return Boolean.FALSE;
        try { return Long.parseLong(raw); } catch (NumberFormatException ignored) {}
        try { return Double.parseDouble(raw); } catch (NumberFormatException ignored) {}
        return raw;
    }

    private List<Object> coerceList(List<String> values) {
        return values.stream().map(this::coerce).collect(Collectors.toList());
    }
}
