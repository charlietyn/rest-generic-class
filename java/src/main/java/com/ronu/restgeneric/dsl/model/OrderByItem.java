package com.ronu.restgeneric.dsl.model;

/**
 * A single ordering directive: a field path (may use dot notation) and a direction.
 */
public record OrderByItem(String path, Direction direction) {

    public enum Direction {
        ASC, DESC;

        public static Direction from(String value) {
            return "desc".equalsIgnoreCase(value) ? DESC : ASC;
        }
    }
}
