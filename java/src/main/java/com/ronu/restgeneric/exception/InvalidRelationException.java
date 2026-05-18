package com.ronu.restgeneric.exception;

import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.ResponseStatus;

@ResponseStatus(HttpStatus.BAD_REQUEST)
public class InvalidRelationException extends RestGenericException {
    public InvalidRelationException(String message) {
        super(message, HttpStatus.BAD_REQUEST);
    }
}
