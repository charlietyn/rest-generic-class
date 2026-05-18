package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

/**
 * Declares which field paths (including dot-notation relation paths) may be
 * used in orderby clauses. If omitted, all local fields are allowed but
 * relation-based ordering is blocked.
 *
 * <pre>{@code
 * @AllowedOrderBy({"name", "createdAt", "department.name"})
 * public class User { ... }
 * }</pre>
 */
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface AllowedOrderBy {
    String[] value() default {};
}
