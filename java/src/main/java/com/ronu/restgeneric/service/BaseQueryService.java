package com.ronu.restgeneric.service;

import com.ronu.restgeneric.dsl.model.DynamicQueryRequest;
import com.ronu.restgeneric.dsl.model.Pagination;
import com.ronu.restgeneric.query.*;
import jakarta.persistence.EntityManager;
import jakarta.persistence.criteria.*;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageImpl;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.jpa.domain.Specification;

import java.util.*;

/**
 * Generic base service providing search capabilities.
 *
 * <p>Uses {@code jakarta.persistence.EntityManager} + JPA Criteria API (jakarta) for all
 * query execution — fully compatible with Spring Boot 3, no Blaze raw API required here.
 *
 * <p>Subclasses provide entity-view mapping via the abstract {@link #toView(Object)} method
 * (or override {@link #searchSinglePhase} / {@link #searchTwoPhase} to plug in Blaze Entity Views).
 *
 * <p><b>Two-phase strategy:</b>
 * <ul>
 *   <li>Phase 1: SELECT e.id FROM entity e WHERE ... ORDER BY ... LIMIT/OFFSET — pure JPQL/criteria</li>
 *   <li>Phase 2: SELECT view WHERE id IN (:ids) — no pagination, no cartesian product</li>
 * </ul>
 *
 * @param <E> JPA entity type
 * @param <V> View / DTO type returned to the REST layer
 */
public abstract class BaseQueryService<E, V> {

    protected final EntityManager em;
    protected final QueryPlanCompiler compiler;
    protected final FilterCompiler filterCompiler;
    protected final OrderByCompiler orderByCompiler;

    protected BaseQueryService(EntityManager em,
                                QueryPlanCompiler compiler,
                                FilterCompiler filterCompiler,
                                OrderByCompiler orderByCompiler) {
        this.em = em;
        this.compiler = compiler;
        this.filterCompiler = filterCompiler;
        this.orderByCompiler = orderByCompiler;
    }

    protected abstract Class<E> entityClass();

    /**
     * Maps a JPA entity to its View representation.
     * Override in subclasses that use Blaze Entity Views, MapStruct, or manual mapping.
     */
    protected abstract V toView(E entity);

    // -------------------------------------------------------------------------
    // Public query API
    // -------------------------------------------------------------------------

    public Page<V> search(DynamicQueryRequest request) {
        QueryPlan plan = compiler.compile(request, entityClass());
        return plan.requiresTwoPhase()
                ? searchTwoPhase(plan)
                : searchSinglePhase(plan);
    }

    public Optional<V> findById(Object id) {
        E entity = em.find(entityClass(), id);
        return Optional.ofNullable(entity).map(this::toView);
    }

    public Optional<V> findByIdWithRelations(Object id, List<String> relations) {
        // Eager-load explicitly requested relations via entity graph
        var graph = em.createEntityGraph(entityClass());
        for (String rel : relations) {
            String[] segments = rel.split("\\.", 2);
            graph.addAttributeNodes(segments[0]);
            if (segments.length > 1) {
                graph.addSubgraph(segments[0]).addAttributeNodes(segments[1]);
            }
        }
        Map<String, Object> hints = Map.of("jakarta.persistence.fetchgraph", graph);
        E entity = em.find(entityClass(), id, hints);
        return Optional.ofNullable(entity).map(this::toView);
    }

    // -------------------------------------------------------------------------
    // Strategy implementations
    // -------------------------------------------------------------------------

    protected Page<V> searchSinglePhase(QueryPlan plan) {
        Specification<E> spec = buildSpec(plan);
        OrderByCompiler.Result<E> orderResult =
                orderByCompiler.compile(plan.orderItems(), entityClass(), plan.relationIsToMany());

        Pagination pg = plan.pagination();
        long total = count(spec);
        List<E> entities = fetchEntities(spec, orderResult, pg);
        List<V> views = entities.stream().map(this::toView).toList();
        return new PageImpl<>(views, pg.toPageable(), total);
    }

    protected Page<V> searchTwoPhase(QueryPlan plan) {
        Specification<E> spec = buildSpec(plan);
        OrderByCompiler.Result<E> orderResult =
                orderByCompiler.compile(plan.orderItems(), entityClass(), plan.relationIsToMany());
        Pagination pg = plan.pagination();

        // Phase 1: count + paginated IDs
        long total = count(spec);
        List<Object> ids = fetchIds(spec, orderResult, pg);

        if (ids.isEmpty()) {
            return new PageImpl<>(List.of(), pg.toPageable(), total);
        }

        // Phase 2: fetch full entities by IDs (no pagination → no cartesian product risk)
        Specification<E> idSpec = (root, q, cb) -> root.get("id").in(ids);
        List<E> entities = fetchEntities(idSpec, orderResult, null);
        List<V> views = entities.stream().map(this::toView).toList();
        return new PageImpl<>(views, pg.toPageable(), total);
    }

    // -------------------------------------------------------------------------
    // Internal JPA Criteria helpers (all jakarta.persistence.criteria.*)
    // -------------------------------------------------------------------------

    private Specification<E> buildSpec(QueryPlan plan) {
        Specification<E> filterSpec = filterCompiler.toSpecification(plan.filterTree());
        OrderByCompiler.Result<E> orderResult =
                orderByCompiler.compile(plan.orderItems(), entityClass(), plan.relationIsToMany());
        if (orderResult.hasSubqueryOrdering()) {
            return Specification.where(filterSpec).and(orderResult.subqueryOrderSpec());
        }
        return Specification.where(filterSpec);
    }

    protected long count(Specification<E> spec) {
        CriteriaBuilder cb = em.getCriteriaBuilder();
        CriteriaQuery<Long> q = cb.createQuery(Long.class);
        Root<E> root = q.from(entityClass());
        q.select(cb.count(root));
        Predicate predicate = spec.toPredicate(root, q, cb);
        if (predicate != null) q.where(predicate);
        return em.createQuery(q).getSingleResult();
    }

    protected List<Object> fetchIds(Specification<E> spec,
                                     OrderByCompiler.Result<E> orderResult,
                                     Pagination pg) {
        CriteriaBuilder cb = em.getCriteriaBuilder();
        CriteriaQuery<Object> q = cb.createQuery(Object.class);
        Root<E> root = q.from(entityClass());
        q.select(root.get("id")).distinct(true);

        Predicate predicate = spec.toPredicate(root, q, cb);
        if (predicate != null) q.where(predicate);

        // Apply sort from the Sort object
        List<Order> orders = new ArrayList<>();
        for (var order : orderResult.sort()) {
            Path<?> path = resolveOrderPath(root, order.getProperty());
            orders.add(order.isAscending() ? cb.asc(path) : cb.desc(path));
        }
        if (!orders.isEmpty()) q.orderBy(orders);

        var query = em.createQuery(q);
        if (pg != null) {
            query.setFirstResult(pg.offset());
            query.setMaxResults(pg.limit());
        }
        return query.getResultList();
    }

    protected List<E> fetchEntities(Specification<E> spec,
                                     OrderByCompiler.Result<E> orderResult,
                                     Pagination pg) {
        CriteriaBuilder cb = em.getCriteriaBuilder();
        CriteriaQuery<E> q = cb.createQuery(entityClass());
        Root<E> root = q.from(entityClass());
        q.select(root);

        Predicate predicate = spec.toPredicate(root, q, cb);
        if (predicate != null) q.where(predicate);

        List<Order> orders = new ArrayList<>();
        for (var order : orderResult.sort()) {
            Path<?> path = resolveOrderPath(root, order.getProperty());
            orders.add(order.isAscending() ? cb.asc(path) : cb.desc(path));
        }
        if (!orders.isEmpty()) q.orderBy(orders);

        var query = em.createQuery(q);
        if (pg != null) {
            query.setFirstResult(pg.offset());
            query.setMaxResults(pg.limit());
        }
        return query.getResultList();
    }

    private Path<?> resolveOrderPath(Root<E> root, String dotPath) {
        String[] parts = dotPath.split("\\.");
        if (parts.length == 1) return root.get(parts[0]);
        Join<?, ?> join = root.join(parts[0], JoinType.LEFT);
        for (int i = 1; i < parts.length - 1; i++) {
            join = join.join(parts[i], JoinType.LEFT);
        }
        return join.get(parts[parts.length - 1]);
    }
}
