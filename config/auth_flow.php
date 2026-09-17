<?php

return [
    'registration_enabled' => (bool) env('AUTH_REGISTRATION_ENABLED', false),

    'otp' => [
        'driver' => env('OTP_DRIVER', 'log'),
        'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 5),
        'fixed_code' => env('OTP_FIXED_CODE'),
    ],

    'bootstrap_admin' => [
        'first_name' => env('BOOTSTRAP_ADMIN_FIRST_NAME') ?: 'Admin',
        'last_name' => env('BOOTSTRAP_ADMIN_LAST_NAME') ?: 'User',
        'mobile' => env('BOOTSTRAP_ADMIN_MOBILE'),
        'email' => env('BOOTSTRAP_ADMIN_EMAIL'),
        'password' => env('BOOTSTRAP_ADMIN_PASSWORD'),
    ],
];
