package com.ronu.restgeneric.dsl.model;

/**
 * A filter scoped to a relation — equivalent to whereHas() in Eloquent.
 * The inner node contains the conditions to apply within the relation's scope.
 */
public record RelationFilterNode(String relationPath, FilterNode inner) implements FilterNode {
}
