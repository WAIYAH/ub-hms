<?php
require __DIR__ . '/includes/bootstrap.php';

// Signing out changes the session, so it must be a POST with a valid token.
if (!is_post()) {
    redirect('index.php');
}
require_post();
if ($u = current_user()) {
    audit('logout', 'user', (int) $u['id']);
}
end_user_session();
session_start();
flash('info', 'You have been signed out.');
redirect('login.php');
