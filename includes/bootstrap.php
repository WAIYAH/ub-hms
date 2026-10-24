<?php
/**
 * Start-up code for every web page: error handling, security headers and the
 * session. Pages begin with: require __DIR__ . '/../includes/bootstrap.php';
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once __DIR__ . '/core.php';

// Errors are logged, never shown to the user (OWASP A05: security misconfiguration).
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', config('paths.logs') . '/php-error.log');

set_exception_handler(static function (Throwable $e): void {
    $ref = strtoupper(bin2hex(random_bytes(3)));
    log_message('error', sprintf('[%s] %s in %s:%d', $ref, $e->getMessage(), basename($e->getFile()), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title>'
        . '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '"></head><body class="plain"><main class="content-plain">'
        . '<section class="error-box"><p class="error-code">500</p><h1>Something went wrong</h1>'
        . '<p>The system could not complete your request. Nothing has been lost. Please try again, and if it keeps happening tell the administrator this reference: <strong>'
        . e($ref) . '</strong>.</p><p><a class="btn" href="' . e(url('index.php')) . '">Go to the dashboard</a></p></section></main></body></html>';
});

/* ---------- Security headers (OWASP A05) ---------- */

if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ---------- Session ---------- */

function start_web_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name((string) config('session.name'));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() !== '' ? base_path() . '/' : '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['user'])) {
        $idle = $now - (int) ($_SESSION['last_activity'] ?? 0);
        $age = $now - (int) ($_SESSION['signed_in_at'] ?? 0);
        if ($idle > (int) config('session.idle_timeout') || $age > (int) config('session.absolute_timeout')) {
            $userId = (int) $_SESSION['user']['id'];
            audit('session_expired', 'user', $userId, 'Signed out automatically', $userId);
            end_user_session();
            session_start();
            flash('info', 'You were signed out because the session was inactive for too long. Please sign in again.');
        }
    }
    $_SESSION['last_activity'] = $now;
}

start_web_session();

// A deactivated user must lose access immediately, not at the next sign-in.
if (($u = current_user()) !== null) {
    $stillActive = db_value('SELECT is_active FROM users WHERE id = ?', [$u['id']]);
    if ($stillActive === null || !(int) $stillActive) {
        end_user_session();
        session_start();
        flash('warning', 'Your account is no longer active.');
        redirect('login.php');
    }
}
