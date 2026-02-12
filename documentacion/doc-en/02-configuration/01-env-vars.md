# Environment variables

The package reads environment variables only from the configuration file. This is safe for config caching.

| Variable | Default | Used for |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Sets the log level for the package logging channel. |
| `LOG_QUERY` | `false` | Enables query logging in controllers when `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Enables column validation for filtering. |
| `REST_STRICT_COLUMNS` | `true` | Enables strict column validation behavior. |
| `REST_VALIDATION_CACHE_ENABLED` | `true` | Enable/disable caching for database validation queries (`ValidatesExistenceInDatabase` trait). |
| `REST_VALIDATION_CACHE_TTL` | `3600` | Cache TTL in seconds for validation queries. |
| `REST_VALIDATION_CACHE_PREFIX` | `'validation'` | Prefix for validation cache keys. |
| `REST_VALIDATION_CONNECTION` | `'db'` | Database connection name used by validation queries. |

**Next:** [Cache strategy](03-cache-strategy.md)

[Back to documentation index](../index.md)
