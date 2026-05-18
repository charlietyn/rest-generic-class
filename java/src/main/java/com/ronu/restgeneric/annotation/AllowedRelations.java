package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

/**
 * Declares which relation paths are allowed to be loaded and filtered on.
 * Equivalent to PHP's {@code const RELATIONS = [...]} on the model.
 *
 * <pre>{@code
 * @AllowedRelations({"department", "roles", "manager.department"})
 * public class User { ... }
 * }</pre>
 */
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface AllowedRelations {
    String[] value();
}
