<?php

return [
    'cache_store' => env('REPOSITORY_CACHE_STORE', 'repository-search'),
    'cache_ttl' => (int) env('REPOSITORY_CACHE_TTL', 300),
];
