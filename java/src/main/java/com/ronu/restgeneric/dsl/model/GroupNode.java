package com.ronu.restgeneric.dsl.model;

import java.util.List;

public record GroupNode(LogicalOp op, List<FilterNode> children) implements FilterNode {
}
