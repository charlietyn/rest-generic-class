package com.ronu.restgeneric.dsl.model;

import java.util.List;

/**
 * Leaf node representing a single filter condition: field|operator|values.
 * Values is a list to support IN, BETWEEN (multi-value operators).
 */
public record ConditionNode(String path, FilterOp op, List<String> values) implements FilterNode {
}
