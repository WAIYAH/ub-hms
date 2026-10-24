<?php
/**
 * Loads everything the application needs except the web-only parts
 * (headers and sessions), so the unit tests can load it too.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

foreach ([
    'helpers', 'db', 'csrf', 'audit', 'auth', 'layout',
    'users', 'patients', 'appointments', 'clinical', 'dashboard', 'health',
] as $module) {
    require_once __DIR__ . '/' . $module . '.php';
}

// Modules added in later sprints are loaded when present.
foreach (['laboratory', 'pharmacy', 'orders', 'billing', 'reports'] as $module) {
    $file = __DIR__ . '/' . $module . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}

date_default_timezone_set((string) config('timezone', 'Africa/Nairobi'));
