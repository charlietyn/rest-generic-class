package com.ronu.restgeneric.exception;

import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.ResponseStatus;

@ResponseStatus(HttpStatus.BAD_REQUEST)
public class PathDepthExceededException extends RestGenericException {
    public PathDepthExceededException(int maxDepth, String path) {
        super("Relation path depth exceeds maximum of " + maxDepth + ": '" + path + "'", HttpStatus.BAD_REQUEST);
    }
}
