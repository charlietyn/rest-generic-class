package com.ronu.restgeneric.dsl;

import com.ronu.restgeneric.dsl.model.*;
import com.ronu.restgeneric.exception.InvalidFilterException;
import com.ronu.restgeneric.exception.InvalidOperatorException;
import org.springframework.stereotype.Component;

import java.util.*;

/**
 * Parses the incoming JSON DSL (oper, relations, orderby, pagination) into typed AST nodes.
 *
 * <p>The {@code oper} map supports three kinds of keys:
 * <ul>
 *   <li>{@code "and"} / {@code "or"} — logical group containing a list of condition strings</li>
 *   <li>A relation name (anything else) — scoped sub-filter, equivalent to whereHas()</li>
 * </ul>
 *
 * <p>Each condition string has the form {@code field|operator|value}, where value may be
 * a comma-separated list for multi-value operators (IN, BETWEEN).
 */
@Component
public class DslParser {

    private static final Set<String> LOGICAL_KEYS = Set.of("and", "or");

    private final int maxDepth;
    private final int maxConditions;

    public DslParser() {
        this(5, 100);
    }

    public DslParser(int maxDepth, int maxConditions) {
        this.maxDepth = maxDepth;
        this.maxConditions = maxConditions;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public Optional<FilterNode> parseOper(Map<String, Object> oper) {
        if (oper == null || oper.isEmpty()) {
            return Optional.empty();
        }
        int[] conditionCounter = {0};
        FilterNode root = parseOperMap(oper, 0, conditionCounter);
        return Optional.of(root);
    }

    public List<OrderByItem> parseOrderBy(List<Map<String, String>> orderby) {
        if (orderby == null) return List.of();
        List<OrderByItem> items = new ArrayList<>();
        for (Map<String, String> entry : orderby) {
            for (Map.Entry<String, String> e : entry.entrySet()) {
                items.add(new OrderByItem(e.getKey(), OrderByItem.Direction.from(e.getValue())));
            }
        }
        return Collections.unmodifiableList(items);
    }

    public List<String> parseRelations(List<String> relations) {
        if (relations == null) return List.of();
        return Collections.unmodifiableList(new ArrayList<>(relations));
    }

    public Pagination parsePagination(Pagination pagination) {
        if (pagination == null) return Pagination.DEFAULT;
        return Pagination.of(pagination.page(), pagination.pageSize());
    }

    // -------------------------------------------------------------------------
    // Internal parsing
    // -------------------------------------------------------------------------

    @SuppressWarnings("unchecked")
    private FilterNode parseOperMap(Map<String, Object> map, int depth, int[] counter) {
        if (depth >= maxDepth) {
            throw new InvalidFilterException(
                    "Filter nesting depth exceeded maximum of " + maxDepth + " levels.");
        }

        List<FilterNode> andChildren = new ArrayList<>();
        List<FilterNode> orChildren = new ArrayList<>();
        List<FilterNode> relationNodes = new ArrayList<>();

        for (Map.Entry<String, Object> entry : map.entrySet()) {
            String key = entry.getKey().toLowerCase(Locale.ROOT);
            Object value = entry.getValue();

            if ("and".equals(key)) {
                andChildren.addAll(parseConditionList(value, depth, counter));
            } else if ("or".equals(key)) {
                orChildren.addAll(parseConditionList(value, depth, counter));
            } else {
                // Relation-scoped sub-filter
                if (!(value instanceof Map)) {
                    throw new InvalidFilterException(
                            "Relation filter key '" + key + "' must contain an object with 'and'/'or' groups.");
                }
                FilterNode inner = parseOperMap((Map<String, Object>) value, depth + 1, counter);
                relationNodes.add(new RelationFilterNode(entry.getKey(), inner));
            }
        }

        List<FilterNode> all = new ArrayList<>();
        if (!andChildren.isEmpty()) {
            all.add(andChildren.size() == 1 ? andChildren.get(0) : new GroupNode(LogicalOp.AND, andChildren));
        }
        if (!orChildren.isEmpty()) {
            all.add(orChildren.size() == 1 ? orChildren.get(0) : new GroupNode(LogicalOp.OR, orChildren));
        }
        all.addAll(relationNodes);

        if (all.size() == 1) return all.get(0);
        return new GroupNode(LogicalOp.AND, all);
    }

    @SuppressWarnings("unchecked")
    private List<FilterNode> parseConditionList(Object value, int depth, int[] counter) {
        if (value instanceof List<?> list) {
            List<FilterNode> nodes = new ArrayList<>();
            for (Object item : list) {
                if (item instanceof String s) {
                    nodes.add(parseConditionString(s, counter));
                } else if (item instanceof Map<?, ?> nested) {
                    // nested group within a list
                    nodes.add(parseOperMap((Map<String, Object>) nested, depth + 1, counter));
                } else {
                    throw new InvalidFilterException(
                            "Each condition must be a string 'field|operator|value', got: " + item);
                }
            }
            return nodes;
        }
        throw new InvalidFilterException(
                "Expected an array of conditions but got: " + (value == null ? "null" : value.getClass().getSimpleName()));
    }

    private ConditionNode parseConditionString(String condition, int[] counter) {
        if (++counter[0] > maxConditions) {
            throw new InvalidFilterException(
                    "Maximum number of filter conditions (" + maxConditions + ") exceeded.");
        }

        String[] parts = condition.split("\\|", 3);
        if (parts.length != 3) {
            throw new InvalidFilterException(
                    "Invalid condition format: '" + condition + "'. Expected 'field|operator|value'.");
        }

        String field = parts[0].trim();
        String operatorSymbol = parts[1].trim();
        String rawValue = parts[2].trim();

        if (field.isEmpty()) {
            throw new InvalidFilterException("Field name cannot be empty in condition: '" + condition + "'.");
        }

        FilterOp op = FilterOp.fromSymbol(operatorSymbol)
                .orElseThrow(() -> new InvalidOperatorException(operatorSymbol));

        List<String> values = splitValues(rawValue, op);
        return new ConditionNode(field, op, values);
    }

    private List<String> splitValues(String raw, FilterOp op) {
        if (op == FilterOp.IN || op == FilterOp.NOT_IN
                || op == FilterOp.BETWEEN || op == FilterOp.NOT_BETWEEN) {
            String[] split = raw.split(",");
            if ((op == FilterOp.BETWEEN || op == FilterOp.NOT_BETWEEN) && split.length != 2) {
                throw new InvalidFilterException(
                        "Operator '" + op.getSymbol() + "' requires exactly 2 comma-separated values.");
            }
            return Arrays.asList(split);
        }
        return List.of(raw);
    }
}
