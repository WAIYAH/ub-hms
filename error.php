<?php
// Used by Apache's ErrorDocument rules for 403, 404 and 500 responses.
require __DIR__ . '/includes/bootstrap.php';
$code = get_int('code') ?? 404;
$messages = [
    403 => 'You do not have access to that page.',
    404 => 'The page you asked for does not exist.',
    500 => 'The system could not complete your request.',
];
abort(isset($messages[$code]) ? $code : 404, $messages[$code] ?? $messages[404]);
