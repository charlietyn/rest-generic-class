package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

/**
 * Restricts which fields are accessible per role.
 * Equivalent to PHP's {@code protected array $fieldsByRole = [...]} on the model.
 *
 * <pre>{@code
 * @FieldsByRole({
 *   @FieldsByRole.RoleFields(role = "admin",   fields = {"salary", "internalCode"}),
 *   @FieldsByRole.RoleFields(role = "manager", fields = {"internalCode"})
 * })
 * public class User { ... }
 * }</pre>
 */
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface FieldsByRole {

    RoleFields[] value();

    @interface RoleFields {
        String role();
        String[] fields();
    }
}
