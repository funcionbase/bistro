<?php

return [
    'cache_ttl' => (int) env('COMPANY_SETTINGS_CACHE_TTL', 3600),
    'cache_enabled' => (bool) env('COMPANY_SETTINGS_CACHE_ENABLED', true),
];
