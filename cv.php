<?php
// cv.php — opens a CV. CVs live outside the web folder, so this is the only way in.
//   Seeker:   cv.php            -> their own CV
//   Employer: cv.php?seeker=ID  -> only if they have a MATCH with that seeker
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/upload.php';

$user = require_login();

if ($user['role'] === 'employer') {
    $seekerId = (int) ($_GET['seeker'] ?? 0);
    $ok = db()->prepare('SELECT 1 FROM matches WHERE employer_id = ? AND seeker_id = ? LIMIT 1');
    $ok->execute([$user['id'], $seekerId]);
    if (!$ok->fetchColumn()) {
        http_response_code(403);
        exit('You can see a CV only after you both match.');
    }
} else {
    $seekerId = (int) $user['id'];
}

$stmt = db()->prepare('SELECT cv_path FROM seeker_profiles WHERE user_id = ?');
$stmt->execute([$seekerId]);
$file = upload_disk_path($stmt->fetchColumn() ?: null);

if (!$file || !str_ends_with($file, '.pdf') || !is_file($file)) {
    http_response_code(404);
    exit('No CV found.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="cv.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
