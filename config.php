<?php
// config.php — app settings.
//
// Don't put real passwords here. install.sh writes them into config.local.php,
// which is loaded first and overrides anything below.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---- Database ------------------------------------------------------
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'job_matching_app');
defined('DB_USER') || define('DB_USER', 'student');   // never 'root' — see install.sh
defined('DB_PASS') || define('DB_PASS', 'student');

// ---- Errors --------------------------------------------------------
// true  = show error details (only on your own machine)
// false = show a friendly page and write details to the server log
defined('APP_DEBUG') || define('APP_DEBUG', false);

// ---- Files ---------------------------------------------------------
// Public images (logos, profile pictures) live inside the site.
defined('PUBLIC_UPLOAD_DIR') || define('PUBLIC_UPLOAD_DIR', __DIR__ . '/uploads');
// CVs are private, so they live OUTSIDE the web folder where no URL can reach them.
// /var/www/your_domain/php_auth  ->  /var/www/swipehire_private
defined('PRIVATE_DIR') || define('PRIVATE_DIR', dirname(__DIR__, 2) . '/swipehire_private');

// ---- Google Maps ---------------------------------------------------
// Optional. Paste a key with the "Maps Embed API" enabled (free) into config.local.php:
//   define('GOOGLE_MAPS_API_KEY', 'AIza...');
// Empty = Google's classic keyless embed is used instead.
defined('GOOGLE_MAPS_API_KEY') || define('GOOGLE_MAPS_API_KEY', '');

// ---- Demo data -----------------------------------------------------
// true = sample companies, jobs and candidates are added automatically (no command needed).
// Set false before real users join, then remove them with: php seed_demo.php --remove
defined('DEMO_DATA') || define('DEMO_DATA', true);

// ---- Login security ------------------------------------------------
defined('MAX_LOGIN_ATTEMPTS') || define('MAX_LOGIN_ATTEMPTS', 5);  // wrong passwords before lock
defined('LOCK_MINUTES')       || define('LOCK_MINUTES', 15);       // how long the lock lasts
defined('REMEMBER_DAYS')      || define('REMEMBER_DAYS', 30);      // "Remember me" lifetime
