<?php
/**
 * UB-HMS default configuration.
 *
 * Do not put real passwords in this file. Override any value in
 * config/config.local.php (ignored by Git) or with the UBHMS_* environment
 * variables used for the database settings below.
 */
declare(strict_types=1);

return [
    'app_name'   => 'Uhai Bora Hospital Management System',
    'short_name' => 'UB-HMS',
    'hospital'   => 'Uhai Bora Hospital',
    'location'   => 'Nairobi County, Kenya',
    'timezone'   => 'Africa/Nairobi',

    'db' => [
        'host' => getenv('UBHMS_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('UBHMS_DB_PORT') ?: '3306',
        'name' => getenv('UBHMS_DB_NAME') ?: 'uhai_bora_hms',
        'user' => getenv('UBHMS_DB_USER') ?: 'root',
        'pass' => getenv('UBHMS_DB_PASS') !== false ? (string) getenv('UBHMS_DB_PASS') : '',
    ],

    'session' => [
        'name'             => 'UBHMSSESSID',
        'idle_timeout'     => 1800,   // seconds without activity before sign-out (30 minutes)
        'absolute_timeout' => 28800,  // seconds since sign-in before sign-out (8 hours)
    ],

    'security' => [
        'min_password_length' => 10,
        'bcrypt_cost'         => 11,  // password hashing work factor
        'max_failed_logins'   => 5,   // failures before an account is locked
        'lockout_minutes'     => 15,
        'ip_failure_limit'    => 20,  // failures from one computer in 15 minutes
        'trusted_proxy'       => false, // only true behind a proxy you control
    ],

    // Appointment rules (Module 1). Day numbers follow ISO-8601: 1 = Monday ... 7 = Sunday.
    'clinic' => [
        'open'          => '08:00',
        'close'         => '17:00',
        'slot_minutes'  => 20,
        'days'          => [1, 2, 3, 4, 5, 6],
        'max_days_ahead' => 90,
    ],

    // Pharmacy rules (Module 4)
    'stock' => [
        'expiry_warning_days' => 90,
    ],

    'billing' => [
        'currency' => 'KSh',
    ],

    'paths' => [
        'logs'    => APP_ROOT . '/storage/logs',
        'backups' => APP_ROOT . '/storage/backups',
    ],
];
