<?php

return [
    'route' => '/api/documents',
    'port' => env('HOCUSPOCUS_PORT', 1234),
    'reverse_proxy_route' => env('HOCUSPOCUS_REVERSE_PROXY_ROUTE'),
    'job_connection' => env('HOCUSPOCUS_LARAVEL_JOB_CONNECTION'),
    'job_queue' => env('HOCUSPOCUS_LARAVEL_JOB_QUEUE'),
    'secret' => env('HOCUSPOCUS_SECRET', ''),
    'access_token_parameter' => 'csrf_token',
    'events' => [
        \Hocuspocus\HocuspocusLaravel::EVENT_ON_CHANGE,
        \Hocuspocus\HocuspocusLaravel::EVENT_ON_CONNECT,
        \Hocuspocus\HocuspocusLaravel::EVENT_ON_DISCONNECT,
        \Hocuspocus\HocuspocusLaravel::EVENT_ON_CREATE_DOCUMENT,
    ],
];
