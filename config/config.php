<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'user' => 'root',
        'password' => '',
        'database' => 'top_college',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => 'http://localhost/web-scraper',
        'log_file' => __DIR__ . '/../logs/scraper.log',
        'python_path' => 'C:\\Users\\sachi\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe',
    ],
    'apis' => [
        'google_geocoding_key' => '',
        'serpapi_key' => '',
        'enable_slow_search' => false,
    ],
];
