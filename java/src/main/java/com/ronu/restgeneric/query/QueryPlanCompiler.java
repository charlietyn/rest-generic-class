package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.DslParser;
import com.ronu.restgeneric.dsl.model.*;
import com.ronu.restgeneric.validation.AllowlistRegistry;
import com.ronu.restgeneric.validation.PathValidator;
import org.springframework.stereotype.Component;

import java.util.*;

/**
 * Orchestrates parsing, validation and plan assembly for a {@link DynamicQueryRequest}.
 */
@Component
public class QueryPlanCompiler {

    private final DslParser parser;
    private final AllowlistRegistry registry;
    private final PathValidator pathValidator;
    private final TwoPhaseDetector twoPhaseDetector;

    public QueryPlanCompiler(DslParser parser, AllowlistRegistry registry,
                             PathValidator pathValidator, TwoPhaseDetector twoPhaseDetector) {
        this.parser = parser;
        this.registry = registry;
        this.pathValidator = pathValidator;
        this.twoPhaseDetector = twoPhaseDetector;
    }

    public QueryPlan compile(DynamicQueryRequest request, Class<?> rootEntity) {
        registry.register(rootEntity);

        Optional<FilterNode> filter = parser.parseOper(request.oper());
        List<String> relations = validateRelations(request.relations(), rootEntity);
        List<OrderByItem> orderItems = validateOrderBy(request.orderby(), rootEntity);
        Pagination pagination = parser.parsePagination(request.pagination());
        Map<String, Boolean> toManyMap = buildToManyMap(relations, orderItems, rootEntity);
        boolean twoPhase = twoPhaseDetector.requiresTwoPhase(rootEntity, relations, pagination);

        return new QueryPlan(
                filter.orElse(null),
                orderItems,
                relations,
                twoPhase,
                pagination,
                toManyMap
        );
    }

    private List<String> validateRelations(List<String> rawRelations, Class<?> rootEntity) {
        List<String> relations = parser.parseRelations(rawRelations);
        for (String rel : relations) {
            pathValidator.validateRelationPath(rootEntity, rel);
        }
        return relations;
    }

    private List<OrderByItem> validateOrderBy(List<Map<String, String>> rawOrderBy, Class<?> rootEntity) {
        List<OrderByItem> items = parser.parseOrderBy(rawOrderBy);
        for (OrderByItem item : items) {
            pathValidator.validateOrderByPath(rootEntity, item.path());
        }
        return items;
    }

    private Map<String, Boolean> buildToManyMap(List<String> relations,
                                                 List<OrderByItem> orderItems,
                                                 Class<?> rootEntity) {
        Map<String, Boolean> map = new LinkedHashMap<>();
        for (String rel : relations) {
            String top = rel.contains(".") ? rel.substring(0, rel.indexOf('.')) : rel;
            map.computeIfAbsent(top, k ->
                    pathValidator.isToManyPath(rootEntity, new String[]{k}));
        }
        for (OrderByItem item : orderItems) {
            String top = item.path().contains(".") ? item.path().substring(0, item.path().indexOf('.')) : item.path();
            map.computeIfAbsent(top, k ->
                    pathValidator.isToManyPath(rootEntity, new String[]{k}));
        }
        return Collections.unmodifiableMap(map);
    }
}
