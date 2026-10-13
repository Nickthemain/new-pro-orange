<?php
// feed.php — next batch of cards as JSON, used when the stack runs low.
// GET job_id (employers), skip=1,2,3 (ids already on screen)
require_once __DIR__ . '/includes/app.php';

$user = current_user();
if (!$user || next_page($user) !== 'home.php') {
    json_out(['error' => 'Please sign in again.'], 401);
}

$skip = array_filter(array_map('intval', explode(',', (string) ($_GET['skip'] ?? ''))));
$skip = array_slice($skip, 0, 100);

if ($user['role'] === 'seeker') {
    json_out(['cards' => seeker_feed((int) $user['id'], $skip)]);
}

$q = db()->prepare("SELECT * FROM jobs WHERE id = ? AND employer_id = ? AND status <> 'closed'");
$q->execute([(int) ($_GET['job_id'] ?? 0), $user['id']]);
$job = $q->fetch();
if (!$job) {
    json_out(['error' => 'Job not found'], 404);
}
json_out(['cards' => employer_feed($job, $skip)]);
