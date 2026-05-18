package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.model.Pagination;
import com.ronu.restgeneric.fixture.UserEntity;
import com.ronu.restgeneric.validation.AllowlistRegistry;
import com.ronu.restgeneric.validation.PathValidator;
import jakarta.persistence.EntityManagerFactory;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.List;
import java.util.Set;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class TwoPhaseDetectorTest {

    @Mock
    EntityManagerFactory emf;

    private AllowlistRegistry registryWith(String... relations) {
        AllowlistRegistry reg = new AllowlistRegistry();
        reg.registerRelations(UserEntity.class, Set.of(relations));
        return reg;
    }

    @Test
    void requiresTwoPhase_noPagination_alwaysFalse() {
        PathValidator validator = mock(PathValidator.class);
        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        assertThat(detector.requiresTwoPhase(UserEntity.class, List.of("roles"), null)).isFalse();
        verifyNoInteractions(validator);
    }

    @Test
    void requiresTwoPhase_paginated_toManyRelation_returnsTrue() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"roles"}))).thenReturn(true);

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        assertThat(detector.requiresTwoPhase(
                UserEntity.class, List.of("roles"), Pagination.of(1, 10))).isTrue();
    }

    @Test
    void requiresTwoPhase_paginated_toOneOnly_returnsFalse() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(any(), any())).thenReturn(false);

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        assertThat(detector.requiresTwoPhase(
                UserEntity.class, List.of("department"), Pagination.of(1, 10))).isFalse();
    }

    @Test
    void requiresTwoPhase_mixedRelations_trueWhenAnyToMany() {
        PathValidator validator = mock(PathValidator.class);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"department"}))).thenReturn(false);
        when(validator.isToManyPath(eq(UserEntity.class), eq(new String[]{"roles"}))).thenReturn(true);

        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        assertThat(detector.requiresTwoPhase(
                UserEntity.class, List.of("department", "roles"), Pagination.of(1, 10))).isTrue();
    }

    @Test
    void requiresTwoPhase_emptyRelations_returnsFalse() {
        PathValidator validator = mock(PathValidator.class);
        TwoPhaseDetector detector = new TwoPhaseDetector(validator);

        assertThat(detector.requiresTwoPhase(
                UserEntity.class, List.of(), Pagination.of(1, 10))).isFalse();
        verifyNoInteractions(validator);
    }
}
