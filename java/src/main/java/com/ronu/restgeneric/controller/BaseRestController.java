package com.ronu.restgeneric.controller;

import com.fasterxml.jackson.core.type.TypeReference;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.ronu.restgeneric.dsl.model.DynamicQueryRequest;
import com.ronu.restgeneric.dsl.model.Pagination;
import com.ronu.restgeneric.service.BaseCrudService;
import org.springframework.data.domain.Page;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.Map;

/**
 * Abstract base controller exposing the full REST surface compatible with the PHP rest-generic-class.
 *
 * <p>Endpoints:
 * <ul>
 *   <li>GET  /                 — list with query params (oper, relations, orderby, page, pageSize)</li>
 *   <li>POST /search           — POST-based search with full JSON DSL body</li>
 *   <li>GET  /{id}             — show single entity by ID</li>
 *   <li>GET  /{id}/with        — show with explicit relations (query param)</li>
 *   <li>POST /                 — create single</li>
 *   <li>PUT  /{id}             — update single</li>
 *   <li>POST /bulk             — bulk create</li>
 *   <li>PUT  /bulk             — bulk update</li>
 *   <li>DELETE /{id}           — delete single</li>
 *   <li>DELETE /bulk           — bulk delete (IDs in body)</li>
 * </ul>
 *
 * @param <E>  JPA entity type
 * @param <V>  Entity View type used for responses
 * @param <ID> Primary key type
 */
public abstract class BaseRestController<E, V, ID> {

    private final ObjectMapper objectMapper = new ObjectMapper();

    protected abstract BaseCrudService<E, V, ID> service();

    // -------------------------------------------------------------------------
    // Read endpoints
    // -------------------------------------------------------------------------

    /**
     * GET / — list with optional query-param DSL.
     * Supports: ?oper=...&relations=...&orderby=...&page=1&pageSize=20
     */
    @GetMapping
    public ResponseEntity<Page<V>> list(
            @RequestParam(required = false) String oper,
            @RequestParam(required = false) List<String> relations,
            @RequestParam(required = false) String orderby,
            @RequestParam(required = false, defaultValue = "1") int page,
            @RequestParam(required = false, defaultValue = "20") int pageSize) {

        DynamicQueryRequest request = buildRequestFromParams(oper, relations, orderby, page, pageSize);
        return ResponseEntity.ok(service().search(request));
    }

    /**
     * POST /search — full JSON DSL body (primary search endpoint, mirrors PHP behavior).
     */
    @PostMapping("/search")
    public ResponseEntity<Page<V>> search(@RequestBody DynamicQueryRequest request) {
        return ResponseEntity.ok(service().search(request));
    }

    /**
     * GET /{id} — single entity without relations.
     */
    @GetMapping("/{id}")
    public ResponseEntity<V> show(@PathVariable ID id) {
        return service().findById(id)
                .map(ResponseEntity::ok)
                .orElse(ResponseEntity.notFound().build());
    }

    /**
     * GET /{id}/with?relations=department,roles — single entity with explicit relations.
     */
    @GetMapping("/{id}/with")
    public ResponseEntity<V> showWithRelations(
            @PathVariable ID id,
            @RequestParam(required = false) List<String> relations) {
        List<String> rels = relations != null ? relations : List.of();
        return service().findByIdWithRelations(id, rels)
                .map(ResponseEntity::ok)
                .orElse(ResponseEntity.notFound().build());
    }

    // -------------------------------------------------------------------------
    // Write endpoints
    // -------------------------------------------------------------------------

    @PostMapping
    public ResponseEntity<V> create(@RequestBody Map<String, Object> body) {
        V created = service().create(body);
        return ResponseEntity.status(HttpStatus.CREATED).body(created);
    }

    @PutMapping("/{id}")
    public ResponseEntity<V> update(@PathVariable ID id, @RequestBody Map<String, Object> body) {
        V updated = service().update(id, body);
        return ResponseEntity.ok(updated);
    }

    @PostMapping("/bulk")
    public ResponseEntity<List<V>> bulkCreate(@RequestBody List<Map<String, Object>> body) {
        return ResponseEntity.status(HttpStatus.CREATED).body(service().bulkCreate(body));
    }

    @PutMapping("/bulk")
    public ResponseEntity<List<V>> bulkUpdate(@RequestBody List<Map<String, Object>> body) {
        return ResponseEntity.ok(service().bulkUpdate(body));
    }

    @DeleteMapping("/{id}")
    public ResponseEntity<Void> delete(@PathVariable ID id) {
        service().delete(id);
        return ResponseEntity.noContent().build();
    }

    @DeleteMapping("/bulk")
    public ResponseEntity<Void> bulkDelete(@RequestBody List<ID> ids) {
        service().bulkDelete(ids);
        return ResponseEntity.noContent().build();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    @SuppressWarnings("unchecked")
    private DynamicQueryRequest buildRequestFromParams(String operJson, List<String> relations,
                                                        String orderbyJson, int page, int pageSize) {
        Map<String, Object> oper = null;
        List<Map<String, String>> orderby = null;

        if (operJson != null && !operJson.isBlank()) {
            try {
                oper = objectMapper.readValue(operJson, new TypeReference<>() {});
            } catch (Exception e) {
                throw new com.ronu.restgeneric.exception.InvalidFilterException(
                        "Invalid 'oper' JSON: " + e.getMessage());
            }
        }

        if (orderbyJson != null && !orderbyJson.isBlank()) {
            try {
                orderby = objectMapper.readValue(orderbyJson, new TypeReference<>() {});
            } catch (Exception e) {
                throw new com.ronu.restgeneric.exception.InvalidFilterException(
                        "Invalid 'orderby' JSON: " + e.getMessage());
            }
        }

        return new DynamicQueryRequest(oper, relations, orderby, Pagination.of(page, pageSize));
    }
}
