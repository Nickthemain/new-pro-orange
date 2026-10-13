<?php
// logout.php — ends the session and deletes the "remember me" token
require_once __DIR__ . '/includes/auth.php';

// Must come from our own Sign out button, so another site can't log users out
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('home.php');
}
check_csrf();

if (!empty($_COOKIE['remember'])) {
    db()->prepare('DELETE FROM refresh_tokens WHERE token_hash = ?')
        ->execute([hash('sha256', $_COOKIE['remember'])]);
    clear_remember_cookie();
}

$_SESSION = [];
session_destroy();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/']);

redirect('login.php');
