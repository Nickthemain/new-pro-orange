<?php
// check.php — setup health check. Only works while APP_DEBUG is on, so it is hidden on a live site.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/upload.php';

if (!APP_DEBUG) {
    http_response_code(404);
    exit('Not found');
}

$checks = [];
$add = function (string $name, bool $ok, string $fix = '') use (&$checks) {
    $checks[] = [$name, $ok, $fix];
};

$add('PHP 8.1 or newer (you have ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1', '>='), 'Upgrade PHP.');
$add('PHP MySQL driver (pdo_mysql)', extension_loaded('pdo_mysql'), 'sudo apt install php-mysql && sudo systemctl restart apache2');
$add('PHP fileinfo extension', extension_loaded('fileinfo'), 'Enable the fileinfo extension in php.ini.');

try {
    db()->query('SELECT 1 FROM users LIMIT 1');
    $add('Database login as "' . DB_USER . '" and tables exist', true);
} catch (Throwable $ex) {
    $add('Database login as "' . DB_USER . '" and tables exist', false, strip_tags(fix_hint($ex)) ?: $ex->getMessage());
}
$add('Not using MySQL root', DB_USER !== 'root', 'Run sudo bash install.sh to create the jobapp user.');

foreach (['image', 'cv'] as $kind) {
    [$dir] = upload_target($kind);
    $add(ucfirst($kind) . ' folder writable: ' . $dir, upload_dir_ready($dir), 'sudo bash install.sh');
}
$add('CV folder is outside the web folder', !str_starts_with(realpath(PRIVATE_DIR) ?: PRIVATE_DIR, $_SERVER['DOCUMENT_ROOT']),
     'Set PRIVATE_DIR in config.php to a folder outside ' . $_SERVER['DOCUMENT_ROOT']);

$toBytes = fn(string $v) => (int) $v * (['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper(substr(trim($v), -1))] ?? 1);
$add('PHP upload limit ≥ 2 MB (' . ini_get('upload_max_filesize') . ')', $toBytes(ini_get('upload_max_filesize')) >= 2097152,
     'Raise upload_max_filesize and post_max_size in php.ini.');

$allOk = !in_array(false, array_column($checks, 1), true);
?>
<!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup check</title>
<style>
  body{font:15px/1.5 system-ui,sans-serif;background:#f6f5f2;color:#16181d;margin:0;padding:32px 16px}
  main{max-width:720px;margin:auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 12px 32px rgba(0,0,0,.08)}
  h1{margin:0 0 4px}.sum{margin:0 0 20px;font-weight:600;color:<?= $allOk ? '#16a34a' : '#c2261d' ?>}
  li{list-style:none;padding:12px 0;border-top:1px solid #eee;display:flex;gap:12px}ul{padding:0;margin:0}
  .i{flex:none;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:.8rem}
  .y{background:#16a34a}.n{background:#c2261d}code{display:block;margin-top:4px;background:#f3f2ee;padding:6px 8px;border-radius:6px;font-size:.85rem;white-space:pre-wrap;word-break:break-all}
</style>
<main>
  <h1>Setup check</h1>
  <p class="sum"><?= $allOk ? 'Everything is ready.' : 'Some things need fixing.' ?></p>
  <ul>
  <?php foreach ($checks as [$name, $ok, $fix]): ?>
    <li><span class="i <?= $ok ? 'y' : 'n' ?>"><?= $ok ? '✓' : '✕' ?></span>
      <div><?= htmlspecialchars($name) ?><?php if (!$ok && $fix): ?><code><?= htmlspecialchars($fix) ?></code><?php endif; ?></div></li>
  <?php endforeach; ?>
  </ul>
  <p style="color:#6b6f78;margin-top:20px">This page disappears when APP_DEBUG is false.</p>
</main>
