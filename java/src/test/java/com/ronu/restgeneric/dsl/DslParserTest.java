package com.ronu.restgeneric.dsl;

import com.ronu.restgeneric.dsl.model.*;
import com.ronu.restgeneric.exception.InvalidFilterException;
import com.ronu.restgeneric.exception.InvalidOperatorException;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;
import java.util.Optional;

import static org.assertj.core.api.Assertions.*;

class DslParserTest {

    private final DslParser parser = new DslParser(5, 100);

    // -------------------------------------------------------------------------
    // parseOper
    // -------------------------------------------------------------------------

    @Test
    void parseOper_null_returnsEmpty() {
        assertThat(parser.parseOper(null)).isEmpty();
    }

    @Test
    void parseOper_emptyMap_returnsEmpty() {
        assertThat(parser.parseOper(Map.of())).isEmpty();
    }

    @Test
    void parseOper_singleAndCondition() {
        Map<String, Object> oper = Map.of("and", List.of("status|=|ACTIVE"));
        Optional<FilterNode> node = parser.parseOper(oper);

        assertThat(node).isPresent();
        assertThat(node.get()).isInstanceOf(ConditionNode.class);
        ConditionNode c = (ConditionNode) node.get();
        assertThat(c.path()).isEqualTo("status");
        assertThat(c.op()).isEqualTo(FilterOp.EQ);
        assertThat(c.values()).containsExactly("ACTIVE");
    }

    @Test
    void parseOper_multipleAndConditions_returnsGroupNode() {
        Map<String, Object> oper = Map.of("and", List.of("status|=|ACTIVE", "name|like|%car%"));
        FilterNode node = parser.parseOper(oper).orElseThrow();

        assertThat(node).isInstanceOf(GroupNode.class);
        GroupNode g = (GroupNode) node;
        assertThat(g.op()).isEqualTo(LogicalOp.AND);
        assertThat(g.children()).hasSize(2);
    }

    @Test
    void parseOper_orConditions() {
        Map<String, Object> oper = Map.of("or", List.of("name|like|%foo%", "email|like|%bar%"));
        FilterNode node = parser.parseOper(oper).orElseThrow();

        assertThat(node).isInstanceOf(GroupNode.class);
        assertThat(((GroupNode) node).op()).isEqualTo(LogicalOp.OR);
    }

    @Test
    void parseOper_betweenValues_splitCorrectly() {
        Map<String, Object> oper = Map.of("and", List.of("createdAt|between|2026-01-01,2026-03-31"));
        ConditionNode c = (ConditionNode) parser.parseOper(oper).orElseThrow();
        assertThat(c.op()).isEqualTo(FilterOp.BETWEEN);
        assertThat(c.values()).containsExactly("2026-01-01", "2026-03-31");
    }

    @Test
    void parseOper_inValues_splitCorrectly() {
        Map<String, Object> oper = Map.of("and", List.of("id|in|1,2,3"));
        ConditionNode c = (ConditionNode) parser.parseOper(oper).orElseThrow();
        assertThat(c.op()).isEqualTo(FilterOp.IN);
        assertThat(c.values()).containsExactly("1", "2", "3");
    }

    @Test
    void parseOper_relationScoped_returnsRelationFilterNode() {
        Map<String, Object> inner = Map.of("and", List.of("active|=|true"));
        Map<String, Object> oper = Map.of("department", inner);
        FilterNode node = parser.parseOper(oper).orElseThrow();

        assertThat(node).isInstanceOf(RelationFilterNode.class);
        RelationFilterNode r = (RelationFilterNode) node;
        assertThat(r.relationPath()).isEqualTo("department");
    }

    @Test
    void parseOper_invalidOperator_throws() {
        Map<String, Object> oper = Map.of("and", List.of("status|INVALID|value"));
        assertThatThrownBy(() -> parser.parseOper(oper))
                .isInstanceOf(InvalidOperatorException.class)
                .hasMessageContaining("INVALID");
    }

    @Test
    void parseOper_missingPipePart_throws() {
        Map<String, Object> oper = Map.of("and", List.of("status|="));
        assertThatThrownBy(() -> parser.parseOper(oper))
                .isInstanceOf(InvalidFilterException.class)
                .hasMessageContaining("field|operator|value");
    }

    @Test
    void parseOper_betweenOneValue_throws() {
        Map<String, Object> oper = Map.of("and", List.of("date|between|2026-01-01"));
        assertThatThrownBy(() -> parser.parseOper(oper))
                .isInstanceOf(InvalidFilterException.class)
                .hasMessageContaining("2 comma-separated values");
    }

    @Test
    void parseOper_depthExceeded_throws() {
        DslParser strictParser = new DslParser(1, 100);
        Map<String, Object> nested = Map.of("and", List.of("x|=|1"));
        Map<String, Object> oper = Map.of("rel", nested); // depth 1 → rel is at depth 0, nested at depth 1
        // rel goes one level deeper than allowed with maxDepth=1
        // This should fail when parsing rel's inner map at depth 1
        assertThatThrownBy(() -> strictParser.parseOper(Map.of("rel", Map.of("and", List.of("x|=|1")))))
                .isInstanceOf(InvalidFilterException.class)
                .hasMessageContaining("depth");
    }

    // -------------------------------------------------------------------------
    // parseOrderBy
    // -------------------------------------------------------------------------

    @Test
    void parseOrderBy_null_returnsEmpty() {
        assertThat(parser.parseOrderBy(null)).isEmpty();
    }

    @Test
    void parseOrderBy_ascAndDesc() {
        List<OrderByItem> items = parser.parseOrderBy(
                List.of(Map.of("name", "asc"), Map.of("createdAt", "DESC")));
        assertThat(items).hasSize(2);
        assertThat(items.get(0).path()).isEqualTo("name");
        assertThat(items.get(0).direction()).isEqualTo(OrderByItem.Direction.ASC);
        assertThat(items.get(1).direction()).isEqualTo(OrderByItem.Direction.DESC);
    }

    @Test
    void parseOrderBy_invalidDirectionDefaultsToAsc() {
        List<OrderByItem> items = parser.parseOrderBy(List.of(Map.of("name", "garbage")));
        assertThat(items.get(0).direction()).isEqualTo(OrderByItem.Direction.ASC);
    }

    // -------------------------------------------------------------------------
    // parsePagination
    // -------------------------------------------------------------------------

    @Test
    void parsePagination_null_returnsDefault() {
        Pagination pg = parser.parsePagination(null);
        assertThat(pg).isEqualTo(Pagination.DEFAULT);
    }

    @Test
    void parsePagination_clampsBelowOne() {
        Pagination pg = parser.parsePagination(new Pagination(0, -5));
        assertThat(pg.page()).isEqualTo(1);
        assertThat(pg.pageSize()).isEqualTo(20);
    }

    @Test
    void parsePagination_clampsAboveMax() {
        Pagination pg = parser.parsePagination(new Pagination(1, 9999));
        assertThat(pg.pageSize()).isEqualTo(1000);
    }

    @Test
    void parsePagination_offsetCalculation() {
        Pagination pg = Pagination.of(3, 10);
        assertThat(pg.offset()).isEqualTo(20);
        assertThat(pg.limit()).isEqualTo(10);
    }
}
