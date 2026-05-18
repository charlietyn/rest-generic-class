package com.ronu.restgeneric.dsl.model;

import java.util.Arrays;
import java.util.Optional;

public enum FilterOp {
    EQ("="),
    NE("!="),
    NE_ALT("<>"),
    GT(">"),
    GE(">="),
    LT("<"),
    LE("<="),
    LIKE("like"),
    NOT_LIKE("not like"),
    ILIKE("ilike"),
    NOT_ILIKE("not ilike"),
    IN("in"),
    NOT_IN("not in"),
    BETWEEN("between"),
    NOT_BETWEEN("not between"),
    NULL("null"),
    NOT_NULL("not null"),
    EXISTS("exists"),
    NOT_EXISTS("not exists"),
    DATE("date"),
    NOT_DATE("not date"),
    REGEXP("regexp"),
    NOT_REGEXP("not regexp");

    private final String symbol;

    FilterOp(String symbol) {
        this.symbol = symbol;
    }

    public String getSymbol() {
        return symbol;
    }

    public static Optional<FilterOp> fromSymbol(String symbol) {
        return Arrays.stream(values())
                .filter(op -> op.symbol.equalsIgnoreCase(symbol.trim()))
                .findFirst();
    }
}
