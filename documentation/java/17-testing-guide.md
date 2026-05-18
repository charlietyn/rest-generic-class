# Chapter 17 — Testing Guide

This chapter covers how to test applications built on `rest-generic-class` at every level:
pure unit tests with no Spring context, unit tests using Mockito, Spring Boot integration tests
against an H2 in-memory database, SQL-level assertion using Hibernate statistics, and test
fixture management with `AllowlistRegistry`.

---

## 17.1 Unit Testing `DslParser` — No Spring Context Required

`DslParser` is a plain Java class with no Spring dependencies. Tests instantiate it directly.
No `@SpringBootTest`, no application context, no database.

```java
import com.ronu.restgeneric.dsl.DslParser;
import com.ronu.restgeneric.dsl.model.*;
import com.ronu.restgeneric.exception.InvalidFilterException;
import com.ronu.restgeneric.exception.InvalidOperatorException;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;
import java.util.Optional;

import static org.assertj.core.api.Assertions.*;

class DslParserTest {

    // Default limits: maxDepth=5, maxConditions=100
    DslParser parser = new DslParser(5, 100);

    // -----------------------------------------------------------------
    // Basic condition parsing
    // -----------------------------------------------------------------

    @Test
    void parsesSimpleAndCondition() {
        Map<String, Object> oper = Map.of("and", List.of("status|=|ACTIVE"));

        Optional<FilterNode> result = parser.parseOper(oper);

        assertThat(result).isPresent();
        assertThat(result.get()).isInstanceOf(ConditionNode.class);
        ConditionNode node = (ConditionNode) result.get();
        assertThat(node.path()).isEqualTo("status");
        assertThat(node.op()).isEqualTo(FilterOp.EQ);
        assertThat(node.values()).containsExactly("ACTIVE");
    }

    @Test
    void parsesInOperatorWithMultipleValues() {
        Map<String, Object> oper = Map.of("and", List.of("status|in|ACTIVE,PENDING"));

        ConditionNode node = (ConditionNode) parser.parseOper(oper).get();

        assertThat(node.op()).isEqualTo(FilterOp.IN);
        assertThat(node.values()).containsExactly("ACTIVE", "PENDING");
    }

    @Test
    void parsesBetweenWithExactlyTwoValues() {
        Map<String, Object> oper = Map.of("and", List.of("price|between|10.00,99.99"));

        ConditionNode node = (ConditionNode) parser.parseOper(oper).get();

        assertThat(node.op()).isEqualTo(FilterOp.BETWEEN);
        assertThat(node.values()).hasSize(2).containsExactly("10.00", "99.99");
    }

    @Test
    void parsesNullOperatorWithEmptyValue() {
        Map<String, Object> oper = Map.of("and", List.of("deletedAt|null|"));

        ConditionNode node = (ConditionNode) parser.parseOper(oper).get();

        assertThat(node.op()).isEqualTo(FilterOp.NULL);
        assertThat(node.values()).isEmpty();
    }

    @Test
    void parsesNestedOrInsideAnd() {
        Map<String, Object> inner = Map.of("or", List.of("country|=|US", "country|=|CA"));
        Map<String, Object> oper = Map.of("and", List.of(inner));

        FilterNode root = parser.parseOper(oper).get();

        // root is a GroupNode(AND) containing a GroupNode(OR)
        assertThat(root).isInstanceOf(GroupNode.class);
        GroupNode andGroup = (GroupNode) root;
        assertThat(andGroup.op()).isEqualTo(LogicalOp.AND);
        assertThat(andGroup.children()).hasSize(1);
        assertThat(andGroup.children().get(0)).isInstanceOf(GroupNode.class);
        GroupNode orGroup = (GroupNode) andGroup.children().get(0);
        assertThat(orGroup.op()).isEqualTo(LogicalOp.OR);
        assertThat(orGroup.children()).hasSize(2);
    }

    @Test
    void parsesRelationScopedFilter() {
        Map<String, Object> deptFilter = Map.of("and", List.of("active|=|true"));
        Map<String, Object> oper = Map.of("department", deptFilter);

        FilterNode root = parser.parseOper(oper).get();

        assertThat(root).isInstanceOf(RelationFilterNode.class);
        RelationFilterNode rel = (RelationFilterNode) root;
        assertThat(rel.relationPath()).isEqualTo("department");
        assertThat(rel.inner()).isInstanceOf(ConditionNode.class);
    }

    @Test
    void returnsEmptyForNullOper() {
        assertThat(parser.parseOper(null)).isEmpty();
    }

    // -----------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------

    @Test
    void throwsOnUnknownOperator() {
        Map<String, Object> oper = Map.of("and", List.of("status|contains|ACTIVE"));

        assertThatThrownBy(() -> parser.parseOper(oper))
            .isInstanceOf(InvalidOperatorException.class)
            .hasMessageContaining("contains");
    }

    @Test
    void throwsOnDepthExceeded() {
        DslParser strictParser = new DslParser(1, 100);  // maxDepth = 1

        // Depth-2 structure: "department" key is one level of nesting
        Map<String, Object> inner = Map.of("and", List.of("active|=|true"));
        Map<String, Object> oper = Map.of("department", inner);

        assertThatThrownBy(() -> strictParser.parseOper(oper))
            .isInstanceOf(InvalidFilterException.class)
            .hasMessageContaining("depth exceeded");
    }

    @Test
    void throwsOnConditionLimitExceeded() {
        DslParser strictParser = new DslParser(5, 2);  // maxConditions = 2

        List<String> conditions = List.of("a|=|1", "b|=|2", "c|=|3");  // 3 > limit of 2
        Map<String, Object> oper = Map.of("and", conditions);

        assertThatThrownBy(() -> strictParser.parseOper(oper))
            .isInstanceOf(InvalidFilterException.class)
            .hasMessageContaining("Maximum number");
    }

    @Test
    void throwsOnMalformedConditionString() {
        // Missing third segment — should be "field|op|value"
        Map<String, Object> oper = Map.of("and", List.of("status|="));

        assertThatThrownBy(() -> parser.parseOper(oper))
            .isInstanceOf(InvalidFilterException.class);
    }

    // -----------------------------------------------------------------
    // OrderBy and pagination parsing
    // -----------------------------------------------------------------

    @Test
    void parsesOrderByAscDesc() {
        List<Map<String, String>> orderby = List.of(
            Map.of("name", "asc"),
            Map.of("createdAt", "desc")
        );

        List<OrderByItem> items = parser.parseOrderBy(orderby);

        assertThat(items).hasSize(2);
        assertThat(items.get(0).path()).isEqualTo("name");
        assertThat(items.get(0).direction()).isEqualTo(OrderByItem.Direction.ASC);
        assertThat(items.get(1).path()).isEqualTo("createdAt");
        assertThat(items.get(1).direction()).isEqualTo(OrderByItem.Direction.DESC);
    }

    @Test
    void parsesOrderByReturnsEmptyListForNull() {
        assertThat(parser.parseOrderBy(null)).isEmpty();
    }

    @Test
    void parsePaginationClampsPageSize() {
        Pagination p = parser.parsePagination(Pagination.of(1, 99999));
        assertThat(p.pageSize()).isEqualTo(1000);
    }

    @Test
    void parsePaginationClampsPageToMinimumOne() {
        Pagination p = parser.parsePagination(Pagination.of(-5, 10));
        assertThat(p.page()).isEqualTo(1);
    }

    @Test
    void parsePaginationReturnsDefaultForNull() {
        Pagination p = parser.parsePagination(null);
        assertThat(p).isEqualTo(Pagination.DEFAULT);
    }
}
```

### Key Points

- Each `DslParser` instance is stateless and thread-safe. A single instance can be shared across
  all test methods.
- The `(int maxDepth, int maxConditions)` constructor lets each test configure strict limits
  without touching `application.properties`.
- `FilterNode` is a sealed interface. Use `assertThat(...).isInstanceOf(ConditionNode.class)`
  to assert on the concrete type, then cast.

---

## 17.2 Unit Testing `AllowlistRegistry`

```java
import com.ronu.restgeneric.validation.AllowlistRegistry;
import org.junit.jupiter.api.Test;

import java.util.Set;

import static org.assertj.core.api.Assertions.*;

class AllowlistRegistryTest {

    // -----------------------------------------------------------------
    // Annotation-based registration
    // -----------------------------------------------------------------

    @Test
    void readsAllowedRelationsFromAnnotation() {
        AllowlistRegistry registry = new AllowlistRegistry();
        // UserEntity must be annotated @AllowedRelations({"department", "roles"})
        registry.register(UserEntity.class);

        assertThat(registry.isRelationAllowed(UserEntity.class, "department")).isTrue();
        assertThat(registry.isRelationAllowed(UserEntity.class, "roles")).isTrue();
        assertThat(registry.isRelationAllowed(UserEntity.class, "secretRelation")).isFalse();
    }

    @Test
    void readsAllowedOrderByFromAnnotation() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.register(UserEntity.class);

        assertThat(registry.isOrderByAllowed(UserEntity.class, "name")).isTrue();
        assertThat(registry.isOrderByAllowed(UserEntity.class, "department.name")).isTrue();
        assertThat(registry.isOrderByAllowed(UserEntity.class, "roles.name")).isFalse();
    }

    @Test
    void registerIsIdempotent() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.register(UserEntity.class);
        registry.register(UserEntity.class);  // second call — must not throw

        assertThat(registry.getAllowedRelations(UserEntity.class)).isNotEmpty();
    }

    @Test
    void unregisteredEntityReturnsFalse() {
        AllowlistRegistry registry = new AllowlistRegistry();
        // ProductEntity not registered
        assertThat(registry.isRelationAllowed(ProductEntity.class, "category")).isFalse();
    }

    // -----------------------------------------------------------------
    // Programmatic registration
    // -----------------------------------------------------------------

    @Test
    void programmaticRelationRegistrationOverridesAnnotation() {
        AllowlistRegistry registry = new AllowlistRegistry();
        // Override: only "department" is allowed programmatically, not "roles"
        registry.registerRelations(UserEntity.class, Set.of("department"));

        assertThat(registry.isRelationAllowed(UserEntity.class, "department")).isTrue();
        assertThat(registry.isRelationAllowed(UserEntity.class, "roles")).isFalse();
    }

    @Test
    void programmaticOrderByRegistration() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.registerOrderBy(UserEntity.class, Set.of("name", "createdAt"));

        assertThat(registry.isOrderByAllowed(UserEntity.class, "name")).isTrue();
        assertThat(registry.isOrderByAllowed(UserEntity.class, "email")).isFalse();
    }

    @Test
    void getAllowedRelationsReturnsImmutableCopy() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.register(UserEntity.class);

        Set<String> allowed = registry.getAllowedRelations(UserEntity.class);
        assertThatThrownBy(() -> allowed.add("injected"))
            .isInstanceOf(UnsupportedOperationException.class);
    }
}
```

---

## 17.3 Unit Testing `TwoPhaseDetector`

```java
import com.ronu.restgeneric.query.TwoPhaseDetector;
import com.ronu.restgeneric.dsl.model.Pagination;
import com.ronu.restgeneric.validation.PathValidator;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.List;

import static org.assertj.core.api.Assertions.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class TwoPhaseDetectorTest {

    @Test
    void noPagination_alwaysReturnsFalse() {
        PathValidator validator = mock(PathValidator.class);
        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        boolean result = detector.requiresTwoPhase(
            UserEntity.class, List.of("roles"), null);  // null pagination

        assertThat(result).isFalse();
        verifyNoInteractions(validator);  // validator never consulted without pagination
    }

    @Test
    void paginatedWithToManyRelation_returnsTrue() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"roles"})))
            .thenReturn(true);

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        boolean result = detector.requiresTwoPhase(
            UserEntity.class, List.of("roles"), Pagination.of(1, 10));

        assertThat(result).isTrue();
    }

    @Test
    void paginatedWithToOneRelationOnly_returnsFalse() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"department"})))
            .thenReturn(false);  // ManyToOne is NOT to-many

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        boolean result = detector.requiresTwoPhase(
            UserEntity.class, List.of("department"), Pagination.of(1, 10));

        assertThat(result).isFalse();
    }

    @Test
    void paginatedWithMixedRelations_trueIfAnyToMany() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"department"})))
            .thenReturn(false);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"roles"})))
            .thenReturn(true);

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        boolean result = detector.requiresTwoPhase(
            UserEntity.class, List.of("department", "roles"), Pagination.of(1, 10));

        assertThat(result).isTrue();
    }

    @Test
    void emptyRelationsList_alwaysReturnsFalse() {
        PathValidator validator = mock(PathValidator.class);
        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        boolean result = detector.requiresTwoPhase(
            UserEntity.class, List.of(), Pagination.of(1, 10));

        assertThat(result).isFalse();
    }
}
```

---

## 17.4 Integration Testing with H2

Spring Boot's test slice `@SpringBootTest` combined with `@AutoConfigureTestDatabase` replaces
the configured datasource with H2. The full application context is loaded, including
`RestGenericAutoConfiguration`, all beans, and the JPA schema.

### Test Class Setup

```java
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.*;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.jdbc.AutoConfigureTestDatabase;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.transaction.annotation.Transactional;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.*;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.*;

@SpringBootTest
@AutoConfigureTestDatabase(replace = AutoConfigureTestDatabase.Replace.ANY)
@AutoConfigureMockMvc
@Transactional  // each test rolls back — no state bleeds between tests
class UserControllerIntegrationTest {

    @Autowired MockMvc mockMvc;
    @Autowired UserRepository userRepository;
    @Autowired DepartmentRepository deptRepository;
    @Autowired ObjectMapper objectMapper;

    private DepartmentEntity engineeringDept;
    private UserEntity alice;

    @BeforeEach
    void setUp() {
        engineeringDept = new DepartmentEntity();
        engineeringDept.setName("Engineering");
        engineeringDept.setActive(true);
        deptRepository.save(engineeringDept);

        alice = new UserEntity();
        alice.setName("Alice");
        alice.setEmail("alice@example.com");
        alice.setStatus("ACTIVE");
        alice.setDepartment(engineeringDept);
        userRepository.save(alice);

        userRepository.flush();  // ensure IDs are assigned before assertions
    }

    // -----------------------------------------------------------------
    // Search endpoint — POST /api/users/search
    // -----------------------------------------------------------------

    @Test
    void searchByStatus_returnsPagedResults() throws Exception {
        String body = """
            {
              "oper": {"and": ["status|=|ACTIVE"]},
              "orderby": [{"name": "asc"}],
              "pagination": {"page": 1, "pageSize": 10}
            }
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.content").isArray())
            .andExpect(jsonPath("$.content[0].name").value("Alice"))
            .andExpect(jsonPath("$.totalElements").value(1))
            .andExpect(jsonPath("$.totalPages").value(1));
    }

    @Test
    void searchByInactiveStatus_returnsEmptyPage() throws Exception {
        String body = """
            {"oper": {"and": ["status|=|INACTIVE"]}, "pagination": {"page": 1, "pageSize": 10}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.totalElements").value(0));
    }

    @Test
    void invalidRelationInRequest_returns400() throws Exception {
        String body = """
            {"relations": ["secret"], "pagination": {"page": 1, "pageSize": 10}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isBadRequest());
    }

    @Test
    void searchWithRelationScopedFilter_returnsCorrectResult() throws Exception {
        String body = """
            {
              "oper": {
                "department": {"and": ["name|=|Engineering"]}
              },
              "relations": ["department"],
              "pagination": {"page": 1, "pageSize": 10}
            }
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.totalElements").value(1));
    }

    @Test
    void unknownOperator_returns400() throws Exception {
        String body = """
            {"oper": {"and": ["status|contains|ACTIVE"]}, "pagination": {"page": 1, "pageSize": 10}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isBadRequest());
    }

    // -----------------------------------------------------------------
    // CRUD endpoints
    // -----------------------------------------------------------------

    @Test
    void getById_returnsUser() throws Exception {
        mockMvc.perform(get("/api/users/{id}", alice.getId()))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.name").value("Alice"))
            .andExpect(jsonPath("$.email").value("alice@example.com"));
    }

    @Test
    void getById_unknownId_returns404() throws Exception {
        mockMvc.perform(get("/api/users/99999"))
            .andExpect(status().isNotFound());
    }

    @Test
    void createUser_returns201() throws Exception {
        String body = """
            {"name": "Bob", "email": "bob@example.com", "status": "ACTIVE"}
            """;

        mockMvc.perform(post("/api/users")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isCreated())
            .andExpect(jsonPath("$.name").value("Bob"))
            .andExpect(jsonPath("$.id").isNumber());
    }

    @Test
    void updateUser_returns200() throws Exception {
        String body = """{"name": "Alice Updated"}""";

        mockMvc.perform(put("/api/users/{id}", alice.getId())
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk())
            .andExpect(jsonPath("$.name").value("Alice Updated"));
    }

    @Test
    void deleteUser_returns204() throws Exception {
        mockMvc.perform(delete("/api/users/{id}", alice.getId()))
            .andExpect(status().isNoContent());
    }

    @Test
    void bulkCreate_returns201() throws Exception {
        String body = """
            [
              {"name": "Carol", "email": "carol@example.com", "status": "ACTIVE"},
              {"name": "Dave",  "email": "dave@example.com",  "status": "PENDING"}
            ]
            """;

        mockMvc.perform(post("/api/users/bulk")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isCreated())
            .andExpect(jsonPath("$").isArray())
            .andExpect(jsonPath("$.length()").value(2));
    }

    // -----------------------------------------------------------------
    // Ordering
    // -----------------------------------------------------------------

    @Test
    void paginationWithoutOrderBy_stillReturnsResults() throws Exception {
        // No orderby — results are non-deterministic but the endpoint must not error
        String body = """{"pagination": {"page": 1, "pageSize": 10}}""";

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());
    }

    @Test
    void orderByRelationalPath_notInAllowedOrderBy_returns400() throws Exception {
        String body = """
            {
              "orderby": [{"department.secret": "asc"}],
              "pagination": {"page": 1, "pageSize": 10}
            }
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isBadRequest());
    }
}
```

### H2 Configuration Tip

Add to `src/test/resources/application-test.yml`:

```yaml
spring:
  datasource:
    url: jdbc:h2:mem:testdb;DB_CLOSE_DELAY=-1;DB_CLOSE_ON_EXIT=false
    driver-class-name: org.h2.Driver
  jpa:
    hibernate:
      ddl-auto: create-drop
    properties:
      hibernate.query.fail_on_pagination_over_collection_fetch: true
```

---

## 17.5 Test Fixtures — Programmatic `AllowlistRegistry` Configuration

Do not rely on `@AllowedRelations` annotations being present on entity classes in tests. If you
want to control the allowlist per test, inject `AllowlistRegistry` and register programmatically
in `@BeforeEach`:

```java
@SpringBootTest
@AutoConfigureTestDatabase(replace = AutoConfigureTestDatabase.Replace.ANY)
@AutoConfigureMockMvc
class AllowlistOverrideTest {

    @Autowired MockMvc mockMvc;
    @Autowired AllowlistRegistry registry;

    @BeforeEach
    void configureAllowlist() {
        // Override annotation-based registration for this test class
        registry.registerRelations(UserEntity.class, Set.of("department", "roles"));
        registry.registerOrderBy(UserEntity.class, Set.of("name", "createdAt", "department.name"));
    }

    @Test
    void allowedRelation_loads() throws Exception {
        String body = """
            {"relations": ["department"], "pagination": {"page": 1, "pageSize": 10}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());
    }

    @Test
    void nonAllowedRelation_returns400() throws Exception {
        String body = """
            {"relations": ["auditLog"], "pagination": {"page": 1, "pageSize": 10}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isBadRequest());
    }
}
```

This pattern also lets you test behavior with an empty allowlist (all relations denied) or a
maximal allowlist (all relations permitted) without modifying entity source files.

---

## 17.6 SQL Assertion with Hibernate Statistics

Use Hibernate's built-in `Statistics` API to assert the number of SQL statements executed during
a test. This is the most reliable way to verify that the two-phase strategy fires, that N+1
problems have not regressed, and that batch loading is working correctly.

```java
import jakarta.persistence.EntityManagerFactory;
import org.hibernate.SessionFactory;
import org.hibernate.stat.Statistics;
import org.junit.jupiter.api.*;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.jdbc.AutoConfigureTestDatabase;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.test.web.servlet.MockMvc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

@SpringBootTest
@AutoConfigureTestDatabase(replace = AutoConfigureTestDatabase.Replace.ANY)
@AutoConfigureMockMvc
class QueryCountTest {

    @Autowired MockMvc mockMvc;
    @Autowired EntityManagerFactory emf;

    private Statistics stats;

    @BeforeEach
    void enableStats() {
        stats = emf.unwrap(SessionFactory.class).getStatistics();
        stats.setStatisticsEnabled(true);
        stats.clear();
    }

    @AfterEach
    void disableStats() {
        stats.setStatisticsEnabled(false);
    }

    @Test
    void twoPhaseSearch_executeThreeQueries() throws Exception {
        // relations=["roles"] is a @ManyToMany + pagination → must trigger two-phase strategy
        // Phase 1: COUNT query   (1 statement)
        // Phase 1: ID query      (1 statement)
        // Phase 2: entity + roles query by ID set  (1 statement)
        // Total: 3
        String body = """
            {
              "relations": ["roles"],
              "orderby": [{"id": "asc"}],
              "pagination": {"page": 1, "pageSize": 10}
            }
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());

        assertThat(stats.getPrepareStatementCount())
            .as("Expected 3 statements: COUNT + IDs + entities+relations")
            .isEqualTo(3);
    }

    @Test
    void singlePhaseSearch_withToOneRelation_executesTwoQueries() throws Exception {
        // department is @ManyToOne (to-one) — single-phase is safe
        // 1 COUNT + 1 entity query with JOIN FETCH department = 2
        String body = """
            {
              "relations": ["department"],
              "orderby": [{"id": "asc"}],
              "pagination": {"page": 1, "pageSize": 10}
            }
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());

        assertThat(stats.getPrepareStatementCount())
            .as("Expected 2 statements: COUNT + entities JOIN FETCH department")
            .isEqualTo(2);
    }

    @Test
    void searchWithNoPagination_executesOneQuery() throws Exception {
        // No pagination → no COUNT query, no two-phase
        String body = """
            {"oper": {"and": ["status|=|ACTIVE"]}}
            """;

        mockMvc.perform(post("/api/users/search")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());

        assertThat(stats.getPrepareStatementCount())
            .as("Expected 1 statement: entities only (no COUNT, no pagination)")
            .isEqualTo(1);
    }
}
```

### Hibernate Statistics in `application-test.yml`

```yaml
spring:
  jpa:
    properties:
      hibernate.generate_statistics: true
```

This enables statistics collection at startup without needing `stats.setStatisticsEnabled(true)`
in `@BeforeEach`. Use `stats.clear()` before each test to reset counters.

---

## 17.7 MockMvc DSL Helper Builders

Reduce boilerplate in test classes by extracting a `SearchRequestBuilder` utility:

```java
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.util.List;
import java.util.Map;

/**
 * Builds JSON request bodies for the search endpoint.
 * Use in tests to reduce boilerplate and keep test cases readable.
 */
class SearchRequestBuilder {

    private final ObjectMapper mapper = new ObjectMapper();

    private Map<String, Object> oper;
    private List<String> relations;
    private List<Map<String, String>> orderby;
    private Map<String, Integer> pagination;

    static SearchRequestBuilder search() {
        return new SearchRequestBuilder();
    }

    SearchRequestBuilder oper(Map<String, Object> oper) {
        this.oper = oper;
        return this;
    }

    SearchRequestBuilder andFilter(String... conditions) {
        this.oper = Map.of("and", List.of(conditions));
        return this;
    }

    SearchRequestBuilder relations(String... rels) {
        this.relations = List.of(rels);
        return this;
    }

    SearchRequestBuilder orderBy(String field, String direction) {
        this.orderby = List.of(Map.of(field, direction));
        return this;
    }

    SearchRequestBuilder page(int page, int pageSize) {
        this.pagination = Map.of("page", page, "pageSize", pageSize);
        return this;
    }

    String build() {
        Map<String, Object> request = new java.util.LinkedHashMap<>();
        if (oper != null) request.put("oper", oper);
        if (relations != null) request.put("relations", relations);
        if (orderby != null) request.put("orderby", orderby);
        if (pagination != null) request.put("pagination", pagination);
        try {
            return mapper.writeValueAsString(request);
        } catch (JsonProcessingException e) {
            throw new RuntimeException(e);
        }
    }
}
```

Usage in tests:

```java
@Test
void searchActiveUsersInEngineeringDept() throws Exception {
    String body = SearchRequestBuilder.search()
        .andFilter("status|=|ACTIVE")
        .relations("department")
        .orderBy("name", "asc")
        .page(1, 10)
        .build();

    mockMvc.perform(post("/api/users/search")
            .contentType(MediaType.APPLICATION_JSON)
            .content(body))
        .andExpect(status().isOk())
        .andExpect(jsonPath("$.totalElements").value(1));
}
```

---

## 17.8 Testing Custom `mapData()` Security Constraints

Verify that the mass-assignment guard prevents unauthorized field writes:

```java
@SpringBootTest
@AutoConfigureTestDatabase(replace = AutoConfigureTestDatabase.Replace.ANY)
@AutoConfigureMockMvc
@Transactional
class MassAssignmentGuardTest {

    @Autowired MockMvc mockMvc;
    @Autowired UserRepository userRepository;

    @Test
    void createUser_cannotSetIsAdmin() throws Exception {
        String body = """
            {"name": "hacker", "email": "hacker@example.com", "isAdmin": true}
            """;

        mockMvc.perform(post("/api/users")
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isCreated());

        UserEntity saved = userRepository.findByEmail("hacker@example.com").orElseThrow();
        assertThat(saved.isAdmin())
            .as("isAdmin must not be settable via the generic create endpoint")
            .isFalse();
    }

    @Test
    void updateUser_cannotEscalateToAdmin() throws Exception {
        UserEntity user = new UserEntity();
        user.setName("Regular");
        user.setEmail("regular@example.com");
        user.setIsAdmin(false);
        userRepository.saveAndFlush(user);

        String body = """{"isAdmin": true}""";

        mockMvc.perform(put("/api/users/{id}", user.getId())
                .contentType(MediaType.APPLICATION_JSON)
                .content(body))
            .andExpect(status().isOk());

        UserEntity updated = userRepository.findById(user.getId()).orElseThrow();
        assertThat(updated.isAdmin()).isFalse();
    }
}
```

---

## 17.9 Recommended Test Dependencies

Add the following to your test scope in `pom.xml`:

```xml
<dependencies>
    <!-- Spring Boot test slice with MockMvc and H2 -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-test</artifactId>
        <scope>test</scope>
        <!-- Includes JUnit 5, AssertJ, Mockito, MockMvc, ObjectMapper -->
    </dependency>

    <!-- H2 in-memory database for integration tests -->
    <dependency>
        <groupId>com.h2database</groupId>
        <artifactId>h2</artifactId>
        <scope>test</scope>
    </dependency>
</dependencies>
```

`spring-boot-starter-test` bundles:
- JUnit 5 (`junit-jupiter`)
- AssertJ (`assertj-core`)
- Mockito (`mockito-core`, `mockito-junit-jupiter`)
- Spring MockMvc (`spring-test`)
- Jackson `ObjectMapper` (via `spring-boot-test-autoconfigure`)

No additional test dependencies are required for the patterns in this chapter.

---

## 17.10 Test Organization Checklist

| Layer | Annotation(s) | When to Use |
|---|---|---|
| `DslParser`, `AllowlistRegistry`, pure logic | None (plain JUnit 5) | Parsing, validation, node-tree assertions |
| `TwoPhaseDetector`, `FilterCompiler` | `@ExtendWith(MockitoExtension.class)` | Behavior requiring mocked collaborators |
| Controller + service + repo + DB | `@SpringBootTest` + `@AutoConfigureTestDatabase` + `@AutoConfigureMockMvc` | End-to-end request/response validation |
| SQL query count verification | Above + `Hibernate Statistics` | Two-phase detection, N+1 regression |
| Security / mass-assignment | `@SpringBootTest` + assertions on persisted state | Verify `mapData()` whitelist is enforced |

Use `@Transactional` at the test class level to roll back all database writes after each test.
Avoid using `@DirtiesContext` — it tears down and rebuilds the full Spring context, which is
expensive. Rolling back transactions achieves state isolation at a fraction of the cost.
