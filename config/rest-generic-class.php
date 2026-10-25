<?php

return [
    'logging' => [
        'rest-generic-class' => [
            'driver' => 'single',
            'path' => storage_path('logs/rest-generic-class.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'channel' => [
            'driver' => 'single',
            'path' => storage_path('logs/rest-generic-class.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'query' => env('LOG_QUERY', false),
    ],
    'filtering' => [
        'max_depth' => 5,
        'max_conditions' => 100,
        'strict_relations' => true,
        'allowed_operators' => ['=', '!=', '<', '>', '<=', '>=', 'like', 'not like',
            'ilike', 'not ilike', 'in', 'not in', 'between',
            'not between', 'null', 'not null', 'date', 'not date'],
        'validate_columns' => env('REST_VALIDATE_COLUMNS', true),
        // A column the table does not have (oper, attr/eq, select, orderby; also another table's
        // `table.column` or an expression) is a 400 before any SQL runs. false: it reaches the database.
        'strict_column_validation' => env('REST_STRICT_COLUMNS', true),
        // A hidden column ($hidden, timestamps included) is a 400 in oper, attr/eq and orderby, and is
        // dropped from select. true: hidden columns are queryable again (pre-3.1 behaviour).
        'allow_hidden_columns' => env('REST_ALLOW_HIDDEN_COLUMNS', false),
        // A structured parameter (oper, attr, eq, orderby, pagination) that is not valid JSON is a 400.
        // false: it is ignored as before 3.1 (the request answers as if it had not been sent).
        'reject_invalid_json' => env('REST_REJECT_INVALID_JSON', true),
        // regexp / not regexp: ~ / !~ on PostgreSQL, REGEXP on MySQL/MariaDB, 400 on SQLite and SQL Server.
        // false: the operator is forwarded verbatim (pre-3.1; e.g. SQLite with a user-defined REGEXP).
        'translate_regexp' => env('REST_TRANSLATE_REGEXP', true),
        'column_cache_ttl' => 3600, // Cache column lists for 1 hour
    ],

    'aggregations' => [
        'max_metrics' => 10,
    ],

    'validation' => [
        'cache_enabled' => env('REST_VALIDATION_CACHE_ENABLED', true),
        'cache_ttl' => (int)env('REST_VALIDATION_CACHE_TTL', 3600),
        'cache_prefix' => env('REST_VALIDATION_CACHE_PREFIX', 'validation'),
        'connection' => env('REST_VALIDATION_CONNECTION', 'db'),
    ],

    'cache' => [
        'enabled' => env('REST_CACHE_ENABLED', false),
        // Any Laravel cache store: redis, database, file, memcached, dynamodb, array...
        'store' => env('REST_CACHE_STORE', env('CACHE_STORE')),
        'ttl' => (int)env('REST_CACHE_TTL', 60),
        'ttl_by_method' => [
            'list_all' => (int)env('REST_CACHE_TTL_LIST', 60),
            'get_one' => (int)env('REST_CACHE_TTL_ONE', 30),
        ],
        // BaseService methods that will be cached if cache is enabled. The cache key will be generated based on the method name and parameters.
        'cacheable_methods' => ['list_all', 'get_one'],
        // Headers to include in the cache key to allow caching different versions of the same resource based on these headers (e.g. for localization or multi-tenancy)
        'vary' => [
            'headers' => ['Accept-Language', 'X-Tenant-Id'],
        ],
    ],

    // Core authorization of eager loads (?relations=). The checks ask Laravel's Gate under the scope
    // that a permission integration grants (AuthorizationContext, e.g. ronu/rest-permissions). null falls
    // back to the legacy permissions.authorization.* key of the same name (2.x configs), then to the default.
    'authorization' => [
        // off: only the RELATIONS whitelist applies. require_read: each to-many relation needs
        // <resource>.<relation>.read or ….manage; a denied one is a 403 with
        // filtering.strict_relations=true and is left out otherwise.
        'eager_relations' => env('REST_AUTHZ_EAGER_RELATIONS'),
        // allow: to-one relations (belongsTo, hasOne, ...) are exempt; require_read: checked too.
        'eager_relations_to_one' => env('REST_AUTHZ_EAGER_RELATIONS_TO_ONE'),
        // Ability suffixes that grant a relation (any of them).
        'relation_abilities' => ['read', 'manage'],
    ],
];
