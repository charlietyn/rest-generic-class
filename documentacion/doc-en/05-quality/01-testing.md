# Testing

This package does not ship with automated tests. Validation should be performed in your host application.

Recommended checks for your app:

- Feature tests for CRUD endpoints using your `RestController` subclasses.
- Tests for `oper` filtering and relation allowlist enforcement.
- Tests for hierarchy listing if you use `HIERARCHY_FIELD_ID`.

[Back to documentation index](../index.md)
