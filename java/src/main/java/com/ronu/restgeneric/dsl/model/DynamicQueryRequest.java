package com.ronu.restgeneric.dsl.model;

import com.fasterxml.jackson.annotation.JsonAlias;
import com.fasterxml.jackson.databind.annotation.JsonDeserialize;

import java.util.List;
import java.util.Map;

/**
 * The incoming JSON request body.
 *
 * <pre>{@code
 * {
 *   "oper": {
 *     "and": ["status|=|ACTIVE"],
 *     "or":  ["name|like|%car%"],
 *     "department": { "and": ["active|=|true"] }
 *   },
 *   "relations": ["department", "manager.department", "roles"],
 *   "orderby": [{"name": "asc"}, {"department.name": "asc"}],
 *   "pagination": {"page": 1, "pageSize": 20}
 * }
 * }</pre>
 */
public record DynamicQueryRequest(
        Map<String, Object> oper,
        List<String> relations,
        List<Map<String, String>> orderby,
        Pagination pagination
) {
    public static DynamicQueryRequest empty() {
        return new DynamicQueryRequest(null, null, null, null);
    }
}
