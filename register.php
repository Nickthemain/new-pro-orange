<?php
// register.php — spec step 1: "Signs up"
require_once __DIR__ . '/includes/auth.php';

if ($u = current_user()) {
    redirect(next_page($u));
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        $errors[] = 'Password must be 72 characters or fewer.';   // bcrypt limit
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
            login_user((int) db()->lastInsertId());
            redirect('choose_role.php');                         // role is still NULL
        } catch (PDOException $ex) {
            if ($ex->errorInfo[1] === 1062) {                     // unique email violated
                $errors[] = 'An account with this email already exists.';
            } else {
                throw $ex;
            }
        }
    }
}

auth_top('Create your account', 'Hiring or job hunting — you pick the side next.');
show_errors($errors);
?>
<form method="post" class="auth-form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email</label>
        <div class="input-wrap">
            <?= icon('mail') ?>
            <input type="email" id="email" name="email" value="<?= e($email) ?>"
                   placeholder="you@example.com" required autocomplete="email">
        </div>
    </div>

    <?= password_input('password', 'Password', 'new-password',
                       'minlength="8" maxlength="72" placeholder="At least 8 characters" data-strength') ?>
    <div class="strength" aria-hidden="true"><span></span></div>

    <?= password_input('confirm_password', 'Confirm password', 'new-password', 'placeholder="Type it again"') ?>

    <button type="submit" class="btn-primary">Create account</button>
</form>

<p class="switch">Already have an account? <a href="login.php">Sign in</a></p>
<?php auth_bottom();
