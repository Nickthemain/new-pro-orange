<?php
// seed_demo.php — (optional) fills the app with demo employers, jobs and candidates so the
// swipe feed isn't empty while you build and demo it.
//
//   You don't need to run this: the app loads demo data by itself (see includes/demo.php).
//   sudo php seed_demo.php            add demo data now (safe to run again)
//   sudo php seed_demo.php --remove   delete ALL demo accounts and their data
//
// Demo accounts use @swipehire.test emails and the password: demo12345
// Some demo candidates "like" your real jobs, and some demo employers "like" your
// real seekers, so swiping right on them creates a match right away.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/demo.php';

$db = db();

if (in_array('--remove', $argv, true)) {
    $n = $db->exec("DELETE FROM users WHERE email LIKE '%@swipehire.test'");   // profiles, jobs, swipes, matches cascade
    echo "Removed $n demo accounts.\n";
    exit;
}

echo demo_seed() . "\n";
echo "Demo login (any demo email, e.g. demo.nimbus@swipehire.test / demo.aarav@swipehire.test), password: demo12345\n";
echo "Remove everything later with: php seed_demo.php --remove\n";
