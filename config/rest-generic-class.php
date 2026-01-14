<?php

return [
    'logging' => [
        'channel' => [
            'driver' => 'single',
            'path' => storage_path('logs/rest-generic-class.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
];
