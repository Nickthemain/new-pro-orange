<?php
// swipe.php — saves one swipe (called by assets/swipe.js) and creates a match
// when both sides are interested (spec 5.1 step 7).
//
// POST job_id, action (right|left), seeker_id (employers only)   Header: X-CSRF
require_once __DIR__ . '/includes/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
$user = current_user();
if (!$user || next_page($user) !== 'home.php') {
    json_out(['error' => 'Please sign in again.'], 401);
}
check_csrf_header();

$uid    = (int) $user['id'];
$role   = $user['role'];
$jobId  = (int) ($_POST['job_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['right', 'left'], true) || $jobId < 1) {
    json_out(['error' => 'Bad request'], 400);
}

// ---- who is swiping on what? Check the user is allowed to. ----------
$job = db()->prepare("SELECT j.id, j.employer_id, j.status, j.title FROM jobs j WHERE j.id = ?");
$job->execute([$jobId]);
$job = $job->fetch();
if (!$job) {
    json_out(['error' => 'Job not found'], 404);
}

if ($role === 'seeker') {
    if ($job['status'] !== 'open') {
        json_out(['error' => 'This job is no longer open.'], 409);
    }
    $seekerId   = $uid;
    $employerId = (int) $job['employer_id'];
} else {
    if ((int) $job['employer_id'] !== $uid) {
        json_out(['error' => 'Not your job'], 403);          // can't swipe for someone else's job
    }
    $seekerId = (int) ($_POST['seeker_id'] ?? 0);
    $chk = db()->prepare("SELECT 1 FROM seeker_profiles sp JOIN users u ON u.id = sp.user_id
                          WHERE sp.user_id = ? AND u.status = 'active' AND u.onboarding_completed = 1");
    $chk->execute([$seekerId]);
    if (!$chk->fetchColumn()) {
        json_out(['error' => 'Candidate not found'], 404);
    }
    $employerId = $uid;
}

// ---- save the swipe (one decision per side per job+seeker) ----------
db()->prepare('INSERT INTO swipes (actor_id, actor_role, job_id, seeker_id, action)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE id = id')                 // already swiped: keep the first decision
    ->execute([$uid, $role, $jobId, $seekerId, $action]);

if ($action === 'left') {
    json_out(['ok' => true]);
}

// ---- did the other side already say yes? → it's a match -------------
$otherRole = $role === 'seeker' ? 'employer' : 'seeker';
$other = db()->prepare("SELECT 1 FROM swipes WHERE job_id = ? AND seeker_id = ? AND actor_role = ? AND action IN ('right','invite')");
$other->execute([$jobId, $seekerId, $otherRole]);
if (!$other->fetchColumn()) {
    json_out(['ok' => true]);
}

$ins = db()->prepare('INSERT IGNORE INTO matches (job_id, employer_id, seeker_id, interview_video_link) VALUES (?, ?, ?, ?)');
$ins->execute([$jobId, $employerId, $seekerId, new_video_link()]);
if ($ins->rowCount() === 0) {
    json_out(['ok' => true]);                                   // match already existed
}

// Details for the "It's a match!" popup
$m = db()->prepare('SELECT m.id, m.interview_video_link, e.company_name, e.logo_path, sp.full_name, sp.picture_path
                    FROM matches m JOIN employer_profiles e ON e.user_id = m.employer_id
                    JOIN seeker_profiles sp ON sp.user_id = m.seeker_id
                    WHERE m.job_id = ? AND m.seeker_id = ?');
$m->execute([$jobId, $seekerId]);
$m = $m->fetch();

json_out(['ok' => true, 'match' => [
    'job'        => $job['title'],
    'video'      => $m['interview_video_link'],
    'company'    => ['name' => $m['company_name'], 'photo' => $m['logo_path'], 'initials' => initials($m['company_name'])],
    'seeker'     => ['name' => $m['full_name'], 'photo' => $m['picture_path'], 'initials' => initials($m['full_name'])],
    'other_name' => $role === 'seeker' ? $m['company_name'] : $m['full_name'],
]]);
