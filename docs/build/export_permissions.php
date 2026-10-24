<?php
/** Print the role and permission table of the application as JSON (used by the documents). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/core.php';
echo json_encode(permission_matrix(), JSON_PRETTY_PRINT), "\n";
