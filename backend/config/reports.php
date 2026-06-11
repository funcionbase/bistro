<?php

return [

    'max_date_range_days' => (int) env('REPORT_MAX_DATE_RANGE_DAYS', 90),

    'download_ttl' => (int) env('REPORT_DOWNLOAD_TTL', 30),

    'storage_disk' => env('REPORT_STORAGE_DISK', 'local'),

];
