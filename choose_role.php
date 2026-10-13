<?php
// choose_role.php — spec step 2: "Looking for an Employee" or "Looking for Work"
require_once __DIR__ . '/includes/auth.php';

$user = require_login();
if ($user['role'] !== null) {
    redirect(next_page($user));                 // role can only be picked once
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $role = $_POST['role'] ?? '';

    if (!in_array($role, ['employer', 'seeker'], true)) {
        $errors[] = 'Pick one option.';
    } else {
        // "AND role IS NULL" stops a double-submit from changing the role later
        db()->prepare('UPDATE users SET role = ?, onboarding_step = 1 WHERE id = ? AND role IS NULL')
            ->execute([$role, $user['id']]);
        redirect('setup.php');                  // spec step 3: complete the setup steps
    }
}

page_top('What are you here for?', 'You can only pick once, so choose the side that fits.');
show_errors($errors);
?>
<form method="post" class="roles">
    <?= csrf_field() ?>
    <button type="submit" name="role" value="employer" class="role-btn">
        <span class="role-icon">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </span>
        <span class="role-text">
            <strong>Looking for an Employee</strong>
            <small>Post jobs, swipe on candidates, invite the right people</small>
        </span>
    </button>
    <button type="submit" name="role" value="seeker" class="role-btn">
        <span class="role-icon">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        </span>
        <span class="role-text">
            <strong>Looking for Work</strong>
            <small>Swipe on jobs that fit you and answer invitations</small>
        </span>
    </button>
</form>
<p class="footer-row"><?= logout_button() ?></p>
<?php page_bottom();
