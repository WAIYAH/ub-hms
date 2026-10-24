<?php
/**
 * Cross-site request forgery protection. Every form carries a secret token
 * that is stored in the user's session; a POST without the right token is
 * refused before any data is changed.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input to place inside every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $submitted): bool
{
    $expected = $_SESSION['csrf'] ?? '';
    return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
}

/**
 * Call at the top of every page that changes data. Refuses anything that is
 * not a POST with a valid token.
 */
function require_post(): void
{
    if (!is_post()) {
        abort(405, 'This action must be sent from a form.');
    }
    if (!csrf_valid($_POST['_csrf'] ?? null)) {
        audit('csrf_rejected', null, null, 'Request refused: missing or wrong security token');
        abort(419, 'Your session form has expired. Go back, reload the page and try again.');
    }
}
