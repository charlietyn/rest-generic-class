package com.ronu.restgeneric.query;

import com.ronu.restgeneric.dsl.model.Pagination;
import com.ronu.restgeneric.validation.PathValidator;
import org.springframework.stereotype.Component;

import java.util.List;

/**
 * Determines whether a paginated query requires two-phase execution.
 *
 * <p>Two-phase is needed when:
 * <ol>
 *   <li>Pagination is requested (non-null)</li>
 *   <li>At least one requested relation is a to-many (collection attribute)</li>
 * </ol>
 *
 * <p>This prevents the Hibernate in-memory pagination issue caused by combining
 * fetch joins over collections with LIMIT/OFFSET.
 * Set {@code hibernate.query.fail_on_pagination_over_collection_fetch=true} to
 * get a hard fail instead of silent in-memory paging.
 */
@Component
public class TwoPhaseDetector {

    private final PathValidator pathValidator;

    public TwoPhaseDetector(PathValidator pathValidator) {
        this.pathValidator = pathValidator;
    }

    public boolean requiresTwoPhase(Class<?> rootEntity, List<String> relations, Pagination pagination) {
        if (pagination == null) return false;
        for (String rel : relations) {
            String[] segments = rel.split("\\.");
            if (pathValidator.isToManyPath(rootEntity, segments)) {
                return true;
            }
        }
        return false;
    }
}
