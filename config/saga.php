<?php

return [
    'default' => env('SAGA_DRIVER', 'database'),

    'on_stale_running' => 'fail', // 'fail' | 'retry'

    'signal_timeout' => null, // seconds a waitFor() may stay unanswered before saga:sweep-signals fails it; null = never

    'running_timeout' => null, // seconds a step may stay Running/Compensating before saga:sweep-stale fails it; null = never

    'drivers' => [
        'database' => [
            'connection' => null, // null = default connection
            'table' => 'saga_steps',
        ],
        'cache' => [
            'store' => null, // null = default cache store; set to 'file' for file-backed sagas
            'ttl' => null,   // null = forever
        ],
    ],
];
