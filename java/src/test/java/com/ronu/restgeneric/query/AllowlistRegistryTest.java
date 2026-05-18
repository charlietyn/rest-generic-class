package com.ronu.restgeneric.query;

import com.ronu.restgeneric.fixture.UserEntity;
import com.ronu.restgeneric.validation.AllowlistRegistry;
import org.junit.jupiter.api.Test;

import static org.assertj.core.api.Assertions.assertThat;

class AllowlistRegistryTest {

    @Test
    void register_readsAllowedRelationsAnnotation() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.register(UserEntity.class);

        assertThat(registry.isRelationAllowed(UserEntity.class, "department")).isTrue();
        assertThat(registry.isRelationAllowed(UserEntity.class, "roles")).isTrue();
        assertThat(registry.isRelationAllowed(UserEntity.class, "manager")).isFalse();
    }

    @Test
    void register_readsAllowedOrderByAnnotation() {
        AllowlistRegistry registry = new AllowlistRegistry();
        registry.register(UserEntity.class);

        assertThat(registry.isOrderByAllowed(UserEntity.class, "name")).isTrue();
        assertThat(registry.isOrderByAllowed(UserEntity.class, "department.name")).isTrue();
        assertThat(registry.isOrderByAllowed(UserEntity.class, "salary")).isFalse();
    }

    @Test
    void noAnnotation_onlyLocalFieldsAllowedForOrderBy() {
        AllowlistRegistry registry = new AllowlistRegistry();
        // DepartmentEntity has no @AllowedOrderBy — so only local (no dot) paths are allowed
        registry.register(com.ronu.restgeneric.fixture.DepartmentEntity.class);

        assertThat(registry.isOrderByAllowed(com.ronu.restgeneric.fixture.DepartmentEntity.class, "name")).isTrue();
        assertThat(registry.isOrderByAllowed(com.ronu.restgeneric.fixture.DepartmentEntity.class, "some.relation")).isFalse();
    }
}
