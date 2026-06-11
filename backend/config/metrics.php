<?php

return [
    'cache_ttl' => env('METRICS_CACHE_TTL', 60),
    'active_orders_ttl' => 30,
    'polling_interval' => env('METRICS_POLLING_INTERVAL', 30),
    'top_items_limit' => env('METRICS_TOP_ITEMS_LIMIT', 10),
    // DEPRECADO: leer de config('orders.operational') / config('orders.revenue').
    // Se mantiene para evitar romper callers externos hasta finalizar la migración.
    'active_order_statuses' => ['pending', 'in_kitchen', 'ready', 'in_transit'],
    'revenue_statuses' => ['completed'],
    'available_periods' => ['today', 'week', 'month', 'custom'],
    // Default America/Bogota — todas las empresas operan en CO.
    'timezone' => env('APP_TIMEZONE', 'America/Bogota'),
    'top_dishes_limit' => env('METRICS_TOP_DISHES_LIMIT', 10),
    'abandonment_alert_threshold' => (int) env('DASHBOARD_ABANDONMENT_ALERT_THRESHOLD', 15),
    'delivery_time_alert_threshold' => (int) env('DASHBOARD_DELIVERY_TIME_ALERT_THRESHOLD', 45),

    // TTLs por widget — permiten ajuste granular sin afectar las métricas generales
    'dashboard_summary_cache_ttl' => env('DASHBOARD_SUMMARY_CACHE_TTL', 60),
    'dashboard_chart_cache_ttl' => env('DASHBOARD_CHART_CACHE_TTL', 300),
    'dashboard_heatmap_cache_ttl' => env('DASHBOARD_HEATMAP_CACHE_TTL', 600),
    'dashboard_metrics_cache_ttl' => env('DASHBOARD_METRICS_CACHE_TTL', 300),
    'dashboard_cache_enabled' => env('DASHBOARD_CACHE_ENABLED', true),
    'dashboard_periods' => ['today', 'week', 'month'],
];
