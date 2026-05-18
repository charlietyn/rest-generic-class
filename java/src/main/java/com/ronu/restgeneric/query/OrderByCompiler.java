package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.model.OrderByItem;
import com.ronu.restgeneric.exception.InvalidRelationException;
import com.ronu.restgeneric.validation.AllowlistRegistry;
import com.ronu.restgeneric.validation.PathValidator;
import jakarta.persistence.criteria.*;
import org.springframework.data.domain.Sort;
import org.springframework.data.jpa.domain.Specification;
import org.springframework.stereotype.Component;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Translates {@link OrderByItem} directives into Spring Data {@link Sort} objects and,
 * for to-many relational paths, into a {@link Specification} that adds a scalar
 * correlated subquery to avoid duplicate rows.
 *
 * <p>Local fields and to-one paths → {@link Sort} (Spring Data handles the join).
 * <p>To-many paths → Specification that injects {@code CriteriaQuery.orderBy(subquery)}.
 */
@Component
public class OrderByCompiler {

    private final AllowlistRegistry registry;
    private final PathValidator pathValidator;

    public OrderByCompiler(AllowlistRegistry registry, PathValidator pathValidator) {
        this.registry = registry;
        this.pathValidator = pathValidator;
    }

    /**
     * Splits the items into a {@link Sort} (for direct path ordering) and a list of
     * Specifications (for to-many scalar subquery ordering).
     */
    public <E> Result<E> compile(List<OrderByItem> items, Class<E> rootEntity,
                                  Map<String, Boolean> relationIsToMany) {
        Sort.TypedSort<E> sort = Sort.sort(rootEntity);
        List<OrderByItem> sortItems = new ArrayList<>();
        List<OrderByItem> subqueryItems = new ArrayList<>();

        for (OrderByItem item : items) {
            validate(rootEntity, item.path());
            boolean isToMany = item.path().contains(".")
                    && Boolean.TRUE.equals(relationIsToMany.get(topSegment(item.path())));
            if (isToMany) {
                subqueryItems.add(item);
            } else {
                sortItems.add(item);
            }
        }

        Sort combined = buildSort(sortItems);
        Specification<E> orderSpec = buildSubqueryOrderSpec(subqueryItems, rootEntity);

        return new Result<>(combined, orderSpec);
    }

    // -------------------------------------------------------------------------

    private Sort buildSort(List<OrderByItem> items) {
        if (items.isEmpty()) return Sort.unsorted();
        List<Sort.Order> orders = items.stream()
                .map(item -> item.direction() == OrderByItem.Direction.ASC
                        ? Sort.Order.asc(item.path())
                        : Sort.Order.desc(item.path()))
                .toList();
        return Sort.by(orders);
    }

    /**
     * For each to-many relational orderby item, builds a Specification that injects
     * a scalar correlated subquery into the CriteriaQuery's ORDER BY clause.
     *
     * <p>This mirrors the PHP implementation's {@code buildOrderingSubquery} —
     * using SELECT LIMIT 1 subquery instead of JOIN to avoid duplicate rows.
     */
    private <E> Specification<E> buildSubqueryOrderSpec(List<OrderByItem> items, Class<E> rootEntity) {
        if (items.isEmpty()) return null;

        return (root, query, cb) -> {
            List<Order> orders = new ArrayList<>(query.getOrderList());

            for (OrderByItem item : items) {
                String[] segments = item.path().split("\\.");
                String finalField = segments[segments.length - 1];
                String[] relPath = java.util.Arrays.copyOf(segments, segments.length - 1);

                Expression<?> scalarExpr = buildScalarSubquery(root, query, cb, relPath, finalField);
                orders.add(item.direction() == OrderByItem.Direction.ASC
                        ? cb.asc(scalarExpr)
                        : cb.desc(scalarExpr));
            }

            query.orderBy(orders);
            return null; // this spec contributes only ORDER BY, not WHERE
        };
    }

    /**
     * Builds a scalar correlated subquery: {@code (SELECT rel.field FROM rel WHERE rel.fk = root.id LIMIT 1)}.
     */
    @SuppressWarnings({"unchecked", "rawtypes"})
    private Expression<?> buildScalarSubquery(Root<?> root, CriteriaQuery<?> query,
                                               CriteriaBuilder cb,
                                               String[] relPath, String finalField) {
        Subquery sub = query.subquery(Comparable.class);
        Root subRoot = sub.correlate(root);

        Join current = null;
        for (String segment : relPath) {
            current = (current == null ? subRoot.join(segment, JoinType.LEFT)
                    : current.join(segment, JoinType.LEFT));
        }

        Expression<?> selection = (current != null)
                ? current.get(finalField)
                : subRoot.get(finalField);

        sub.select((Expression) selection);
        return sub;
    }

    private void validate(Class<?> rootEntity, String path) {
        if (!registry.isOrderByAllowed(rootEntity, path)) {
            throw new InvalidRelationException(
                    "OrderBy path '" + path + "' is not allowed on " + rootEntity.getSimpleName());
        }
    }

    private String topSegment(String path) {
        return path.contains(".") ? path.substring(0, path.indexOf('.')) : path;
    }

    public record Result<E>(Sort sort, Specification<E> subqueryOrderSpec) {
        public boolean hasSubqueryOrdering() {
            return subqueryOrderSpec != null;
        }
    }
}
