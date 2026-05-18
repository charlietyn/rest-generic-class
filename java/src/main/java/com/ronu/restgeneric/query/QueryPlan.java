package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.model.FilterNode;
import com.ronu.restgeneric.dsl.model.OrderByItem;
import com.ronu.restgeneric.dsl.model.Pagination;

import java.util.List;
import java.util.Map;

/**
 * Compiled, validated representation of a {@link com.ronu.restgeneric.dsl.model.DynamicQueryRequest}.
 * Created by {@link QueryPlanCompiler} and consumed by the service layer.
 *
 * <p>{@code relationIsToMany} maps top-level relation segment → whether it is a collection.
 * Used by {@link OrderByCompiler} to choose scalar subquery vs direct sort.
 */
public record QueryPlan(
        FilterNode filterTree,
        List<OrderByItem> orderItems,
        List<String> requestedRelations,
        boolean requiresTwoPhase,
        Pagination pagination,
        Map<String, Boolean> relationIsToMany
) {
    public boolean isPaginated() {
        return pagination != null;
    }
}
