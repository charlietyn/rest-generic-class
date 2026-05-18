package com.ronu.restgeneric.exception;

import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.ResponseStatus;

@ResponseStatus(HttpStatus.BAD_REQUEST)
public class InvalidOperatorException extends RestGenericException {
    public InvalidOperatorException(String symbol) {
        super("Unsupported filter operator: '" + symbol + "'. Check rest-generic.filtering.allowed-operators for the full list.", HttpStatus.BAD_REQUEST);
    }
}
