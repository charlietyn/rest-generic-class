package com.ronu.restgeneric.dsl.model;

public sealed interface FilterNode permits GroupNode, ConditionNode, RelationFilterNode {
}
