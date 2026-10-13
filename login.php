<?php
// login.php — spec step 1: "Signs in"
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
    $remember = !empty($_POST['remember']);

    $stmt = db()->prepare('SELECT id, password_hash, role, status, failed_login_attempts, lock_until,
                                  (lock_until IS NOT NULL AND lock_until > NOW()) AS is_locked
                           FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // same timing as a real check
        $errors[] = 'Wrong email or password.';
    } elseif ($user['is_locked']) {
        $errors[] = 'Too many failed attempts. Try again in ' . LOCK_MINUTES . ' minutes.';
    } elseif (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            db()->prepare('UPDATE users SET failed_login_attempts = 0,
                           lock_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?')
                ->execute([LOCK_MINUTES, $user['id']]);
            $errors[] = 'Too many failed attempts. Try again in ' . LOCK_MINUTES . ' minutes.';
        } else {
            db()->prepare('UPDATE users SET failed_login_attempts = ? WHERE id = ?')
                ->execute([$attempts, $user['id']]);
            $errors[] = 'Wrong email or password.';
        }
    } elseif ($user['status'] !== 'active') {
        $errors[] = 'This account is ' . $user['status'] . '. Contact support.';
    } else {
        // Upgrade old hashes automatically if PHP's default algorithm changes
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        login_user((int) $user['id']);
        if ($remember) {
            create_remember_token((int) $user['id']);
        }
        redirect('index.php');                     // index.php sends them to role / setup / home
    }
}

auth_top('Welcome back', 'Sign in to see your matches and new cards.');
show_errors($errors);
?>
<form method="post" class="auth-form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email</label>
        <div class="input-wrap">
            <?= icon('mail') ?>
            <input type="email" id="email" name="email" value="<?= e($email) ?>"
                   placeholder="you@example.com" required autocomplete="email"
                   <?= $email === '' ? 'autofocus' : '' ?>>
        </div>
    </div>

    <?= password_input('password', 'Password', 'current-password',
                       $email !== '' ? 'autofocus placeholder="Your password"' : 'placeholder="Your password"') ?>

    <label class="check">
        <input type="checkbox" name="remember" value="1">
        <span>Keep me signed in for <?= REMEMBER_DAYS ?> days</span>
    </label>

    <button type="submit" class="btn-primary">Sign in</button>
</form>

<p class="switch">New to <?= APP_NAME ?>? <a href="register.php">Create an account</a></p>
<?php auth_bottom();
