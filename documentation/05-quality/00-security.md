# Security

## Relation allowlist

The service enforces allowlisted relations via `const RELATIONS`. When `filtering.strict_relations` is enabled (default), any relation outside the allowlist triggers an error.

## Filter limits

The filtering engine limits `oper` depth and total conditions with `filtering.max_depth` and `filtering.max_conditions` to protect the database from abusive queries.

## Operator allowlist

Only operators defined in `filtering.allowed_operators` are accepted. Unsupported operators throw an exception.

## Column validation

`filtering.validate_columns` and `filtering.strict_column_validation` are used to validate column usage before queries are applied.

## Permissions

If you use the Spatie integration, ensure the authorization middleware runs after tenant/guard resolution. The middleware checks permissions using Spatie's cache, which avoids repeated DB queries.

## Environment usage

Environment variables are only referenced in the config file, so the package is compatible with Laravel's config caching best practices.

[Back to documentation index](../index.md)
