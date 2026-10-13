<?php
// index.php — entry point: send people to the right page
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
redirect($user ? next_page($user) : 'login.php');
