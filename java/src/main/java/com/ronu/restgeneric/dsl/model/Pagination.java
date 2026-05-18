package com.ronu.restgeneric.dsl.model;

import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Pageable;

public record Pagination(int page, int pageSize) {

    public static final Pagination DEFAULT = new Pagination(1, 20);

    public int offset() {
        return (page - 1) * pageSize;
    }

    public int limit() {
        return pageSize;
    }

    public Pageable toPageable() {
        return PageRequest.of(page - 1, pageSize);
    }

    public static Pagination of(int page, int pageSize) {
        if (page < 1) page = 1;
        if (pageSize < 1) pageSize = 20;
        if (pageSize > 1000) pageSize = 1000;
        return new Pagination(page, pageSize);
    }
}
