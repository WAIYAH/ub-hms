<?php
require __DIR__ . '/includes/bootstrap.php';

// Someone who types the site address itself (not index.php or a saved page) and is not signed in sees the welcome page first.
if (!current_user() && rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/') === rtrim(base_path(), '/')) {
    redirect('welcome.php');
}

$user = require_login();

// The dashboard is built from the signed-in user's role (see includes/dashboard.php).
page_start('Dashboard', 'home', ['no_title' => true]);
echo render_dashboard($user);
page_end();
