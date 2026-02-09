# Environment variables

The package reads environment variables only from the configuration file. This is safe for config caching.

| Variable | Default | Used for |
| --- | --- | --- |
| `LOG_LEVEL` | `debug` | Sets the log level for the package logging channel. |
| `LOG_QUERY` | `false` | Enables query logging in controllers when `true`. |
| `REST_VALIDATE_COLUMNS` | `true` | Enables column validation for filtering. |
| `REST_STRICT_COLUMNS` | `true` | Enables strict column validation behavior. |

**Next:** [Cache strategy](03-cache-strategy.md)

[Back to documentation index](../index.md)
