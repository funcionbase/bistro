<?php

return [
    'code' => [
        'min_length' => env('COUPON_CODE_MIN_LENGTH', 4),
        'max_length' => env('COUPON_CODE_MAX_LENGTH', 20),
    ],
    'validation' => [
        'max_percentage' => env('COUPON_MAX_VALUE_PERCENTAGE', 80),
        'max_fixed_amount' => env('COUPON_MAX_FIXED_VALUE', 100000),
        'enable_first_order_check' => env('COUPON_ENABLE_FIRST_ORDER_VALIDATION', true),
    ],
];
