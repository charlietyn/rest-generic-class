package com.ronu.restgeneric.annotation;

import java.lang.annotation.*;

/**
 * Lists entity classes whose cache should be invalidated when this entity is written.
 * Equivalent to PHP's {@code const CACHE_INVALIDATES = [...]}.
 */
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.RUNTIME)
@Documented
public @interface CacheInvalidates {
    Class<?>[] value();
}
