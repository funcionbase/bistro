<?php

return [
    'compression_enabled' => env('RESPONSE_COMPRESSION_ENABLED', false),
    'menu_thumbnail_width' => (int) env('MENU_IMAGE_THUMBNAIL_WIDTH', 400),
    'menu_thumbnail_height' => (int) env('MENU_IMAGE_THUMBNAIL_HEIGHT', 300),
    'api_default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 20),
    'api_max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),
];
