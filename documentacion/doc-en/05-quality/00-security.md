# Security

## Relation allowlist

The service enforces allowlisted relations via `const RELATIONS`. When `filtering.strict_relations` is enabled (default), any relation outside the allowlist triggers an error.

## Filter limits

The filtering engine limits `oper` depth and total conditions with `filtering.max_depth` and `filtering.max_conditions` to protect the database from abusive queries.

## Operator allowlist

Only the operators of the [filter's list](../03-usage/00-basic-usage.md#query-rules-v310) are accepted; any other is a 400.

## Column validation

With `filtering.strict_column_validation` (default `true`) every column of `oper`, `attr`/`eq`, `select` and `orderby` is checked against the table's listing before anything runs: an unknown column, another table's or an expression is a 400 that carries no SQL.

## Hidden columns

What the model hides (`$hidden`, timestamps included) is not queryable either: a `like` on a value the response hides recovers it one character at a time (`password|like|$2y$%`). It is a 400 in `oper`, `attr`/`eq` and `orderby`, also through relations and on relation endpoints, and it is dropped from `select`. The `create` response is serialised like `update`'s, without hidden attributes and with casts. `filtering.allow_hidden_columns=true` restores the pre-3.1 behaviour.

## Permissions

If you use the Spatie integration, ensure the authorization middleware runs after tenant/guard resolution. The middleware checks permissions using Spatie's cache, which avoids repeated DB queries.

## Environment usage

Environment variables are only referenced in the config file, so the package is compatible with Laravel's config caching best practices.

[Back to documentation index](../index.md)
