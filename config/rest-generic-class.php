<?php

return [
    'logging' => [
        'rest-generic-class' => [
            'driver' => 'single',
            'path' => storage_path('logs/rest-generic-class.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
    'filtering' => [
        'max_depth' => 5,
        'max_conditions' => 100,
        'strict_relations' => true,
        'allowed_operators' => ['=', '!=', '<', '>', '<=', '>=', 'like', 'not like',
            'ilike', 'not ilike', 'in', 'not in', 'between',
            'not between', 'null', 'not null', 'exists',
            'not exists', 'date', 'not date'],
    ],
];
