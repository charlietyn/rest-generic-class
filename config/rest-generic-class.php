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
            'not between', 'null', 'not null', 'exists',
            'not exists', 'date', 'not date'],
        'validate_columns' => env('REST_VALIDATE_COLUMNS', true),
        'strict_column_validation' => env('REST_STRICT_COLUMNS', true),
        'column_cache_ttl' => 3600, // Cache column lists for 1 hour
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
        // Métodos de BaseService a cachear
        'cacheable_methods' => ['list_all', 'get_one'],
        // Headers que alteran la respuesta y por tanto la clave de caché
        'vary' => [
            'headers' => ['Accept-Language', 'X-Tenant-Id'],
        ],
    ],
];
