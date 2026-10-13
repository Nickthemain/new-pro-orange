<?php
// setup.php — spec step 3: profile setup wizard
//   Employer (5.1): company, location, industry, position, employment type, salary, job detail, logo
//   Seeker   (5.2): name, age (DOB), location, nationality, qualification, experience,
//                   expected salary, employment type, industries, picture, CV
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/upload.php';
require_once __DIR__ . '/includes/form.php';

$user = require_login();
if ($user['role'] === null) {
    redirect('choose_role.php');
}
if ($user['onboarding_completed']) {
    redirect('home.php');
}

$uid     = (int) $user['id'];
$role    = $user['role'];
$steps   = SETUP_STEPS[$role];
$total   = count($steps);
$reached = min(max(1, (int) $user['onboarding_step']), $total);   // furthest step unlocked

// Which step to show: the one asked for, but never past the furthest unlocked step
$step = (int) ($_POST['step'] ?? $_GET['step'] ?? $reached);
if ($step < 1 || $step > $reached) {
    $step = $reached;
}

$errors  = [];
$profile = load_profile($role, $uid);
$v       = $profile;                       // values shown in the form

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file bigger than post_max_size empties $_POST completely — explain that instead of a CSRF error
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The upload is too large for the server. Use a smaller file.';
    } else {
        check_csrf();
        $save   = 'save_' . $role . '_' . $step;
        $errors = $save($uid, $profile);

        if (!$errors) {
            if ($step === $total) {
                db()->prepare('UPDATE users SET onboarding_completed = 1, onboarding_step = ? WHERE id = ?')
                    ->execute([$total, $uid]);
                if ($role === 'employer') {
                    ensure_first_job($uid);             // position/salary/detail from setup become job #1
                }
                redirect('home.php');
            }
            db()->prepare('UPDATE users SET onboarding_step = GREATEST(onboarding_step, ?) WHERE id = ?')
                ->execute([$step + 1, $uid]);
            redirect('setup.php?step=' . ($step + 1));
        }
        $v = array_merge($profile, $_POST);    // keep what the user typed
    }
}

/* =====================================================================
   Load existing profile (so Back / reload shows saved answers)
   ===================================================================== */
function load_profile(string $role, int $uid): array
{
    $table = $role === 'employer' ? 'employer_profiles' : 'seeker_profiles';
    $stmt  = db()->prepare("SELECT * FROM $table WHERE user_id = ?");   // $table is from our own code, not user input
    $stmt->execute([$uid]);
    $p = $stmt->fetch() ?: [];

    if ($role === 'seeker') {
        $q = db()->prepare('SELECT employment_type FROM seeker_employment_types WHERE user_id = ?');
        $q->execute([$uid]);
        $p['employment_types'] = $q->fetchAll(PDO::FETCH_COLUMN);
        $q = db()->prepare('SELECT industry FROM seeker_industries WHERE user_id = ?');
        $q->execute([$uid]);
        $p['industries'] = $q->fetchAll(PDO::FETCH_COLUMN);
    }
    return $p;
}

/* =====================================================================
   Validation helpers
   ===================================================================== */
function post_str(string $k, int $max = 150): string
{
    return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
}

function need(string $value, string $label, array &$errors, int $min = 2): void
{
    if (mb_strlen($value) < $min) {
        $errors[] = "$label is required.";
    }
}

function in_list(string $value, array $list): bool
{
    return in_array($value, array_is_list($list) ? $list : array_keys($list), true);
}

// Returns [min, max, currency, period] or adds errors
function read_salary(string $minKey, string $maxKey, string $prefix, array &$errors): array
{
    $num = function (string $k) use (&$errors): ?string {
        $raw = trim((string) ($_POST[$k] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw)) {
            $errors[] = 'Salary must be a positive number.';
            return null;
        }
        return $raw;
    };
    $min = $num($minKey);
    $max = $num($maxKey);
    if ($min !== null && $max !== null && (float) $min > (float) $max) {
        $errors[] = 'Minimum salary cannot be higher than maximum.';
    }
    $cur = (string) ($_POST[$prefix . 'currency'] ?? 'INR');
    $per = (string) ($_POST[$prefix . 'period'] ?? 'month');
    if (!in_list($cur, CURRENCIES) || !in_list($per, SALARY_PERIODS)) {
        $errors[] = 'Pick a valid currency and period.';
    }
    return [$min, $max, $cur, $per];
}

/* =====================================================================
   EMPLOYER steps
   ===================================================================== */
function save_employer_1(int $uid, array $p): array
{
    $errors  = [];
    $company = post_str('company_name');
    $industry = (string) ($_POST['industry'] ?? '');
    need($company, 'Company name', $errors);
    if (!in_list($industry, INDUSTRIES)) {
        $errors[] = 'Pick an industry.';
    }
    $logo = $errors ? null : handle_upload('logo', 'image', $errors);
    if ($errors) {
        delete_upload($logo);
        return $errors;
    }

    db()->prepare('INSERT INTO employer_profiles (user_id, company_name, industry, logo_path)
                   VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), industry = VALUES(industry),
                                           logo_path = COALESCE(VALUES(logo_path), logo_path)')
        ->execute([$uid, $company, $industry, $logo]);
    if ($logo) {
        delete_upload($p['logo_path'] ?? null);   // remove the old logo
    }
    return [];
}

function save_employer_2(int $uid, array $p): array
{
    $errors  = [];
    $city    = post_str('city', 100);
    $country = post_str('country', 100);
    need($city, 'City', $errors);
    need($country, 'Country', $errors);
    if ($errors) {
        return $errors;
    }
    db()->prepare('UPDATE employer_profiles SET city = ?, country = ? WHERE user_id = ?')
        ->execute([$city, $country, $uid]);
    return [];
}

function save_employer_3(int $uid, array $p): array
{
    $errors   = [];
    $position = post_str('position');
    $type     = (string) ($_POST['employment_type'] ?? '');
    $detail   = trim((string) ($_POST['job_detail'] ?? ''));
    need($position, 'Position', $errors);
    if (!in_list($type, EMPLOYMENT_TYPES)) {
        $errors[] = 'Pick an employment type.';
    }
    if (mb_strlen($detail) > 5000) {
        $errors[] = 'Job detail must be 5000 characters or fewer.';
    }
    [$min, $max, $cur, $per] = read_salary('salary_min', 'salary_max', 'salary_', $errors);
    if ($errors) {
        return $errors;
    }
    db()->prepare('UPDATE employer_profiles SET position = ?, employment_type = ?, salary_min = ?, salary_max = ?,
                   salary_currency = ?, salary_period = ?, job_detail = ? WHERE user_id = ?')
        ->execute([$position, $type, $min, $max, $cur, $per, $detail === '' ? null : $detail, $uid]);
    return [];
}

/* =====================================================================
   SEEKER steps
   ===================================================================== */
function save_seeker_1(int $uid, array $p): array
{
    $errors      = [];
    $name        = post_str('full_name');
    $nationality = post_str('nationality', 100);
    $dob         = trim((string) ($_POST['date_of_birth'] ?? ''));
    need($name, 'Full name', $errors);

    if ($dob !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $errors[] = 'Enter a valid date of birth.';
        } else {
            $age = $d->diff(new DateTime('today'))->y;
            if ($d > new DateTime('today') || $age < 16 || $age > 100) {
                $errors[] = 'You must be at least 16 years old.';
            }
        }
    }
    $pic = $errors ? null : handle_upload('picture', 'image', $errors);
    if ($errors) {
        delete_upload($pic);
        return $errors;
    }

    db()->prepare('INSERT INTO seeker_profiles (user_id, full_name, date_of_birth, nationality, picture_path)
                   VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), date_of_birth = VALUES(date_of_birth),
                                           nationality = VALUES(nationality),
                                           picture_path = COALESCE(VALUES(picture_path), picture_path)')
        ->execute([$uid, $name, $dob === '' ? null : $dob, $nationality === '' ? null : $nationality, $pic]);
    if ($pic) {
        delete_upload($p['picture_path'] ?? null);
    }
    return [];
}

function save_seeker_2(int $uid, array $p): array
{
    $errors  = [];
    $city    = post_str('city', 100);
    $country = post_str('country', 100);
    $qual    = (string) ($_POST['qualification'] ?? '');
    $exp     = (string) ($_POST['experience_years'] ?? '0');
    need($city, 'City', $errors);
    need($country, 'Country', $errors);
    if ($qual !== '' && !in_list($qual, QUALIFICATIONS)) {
        $errors[] = 'Pick a valid qualification.';
    }
    if (!ctype_digit($exp) || (int) $exp > 50) {
        $errors[] = 'Experience must be a whole number of years (0–50).';
    }
    if ($errors) {
        return $errors;
    }
    db()->prepare('UPDATE seeker_profiles SET city = ?, country = ?, qualification = ?, experience_years = ? WHERE user_id = ?')
        ->execute([$city, $country, $qual === '' ? null : $qual, (int) $exp, $uid]);
    return [];
}

function save_seeker_3(int $uid, array $p): array
{
    $errors = [];
    $types  = array_values(array_unique((array) ($_POST['employment_types'] ?? [])));
    $inds   = array_values(array_unique((array) ($_POST['industries'] ?? [])));

    if (!$types || array_diff($types, array_keys(EMPLOYMENT_TYPES))) {
        $errors[] = 'Pick at least one employment type.';
    }
    if (!$inds || array_diff($inds, INDUSTRIES)) {
        $errors[] = 'Pick at least one industry.';
    } elseif (count($inds) > 5) {
        $errors[] = 'Pick up to 5 industries.';
    }
    [$min, $max, $cur, $per] = read_salary('expected_salary_min', 'expected_salary_max', 'salary_', $errors);
    if ($errors) {
        return $errors;
    }

    // All-or-nothing: profile update + both lists
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE seeker_profiles SET expected_salary_min = ?, expected_salary_max = ?,
                      salary_currency = ?, salary_period = ? WHERE user_id = ?')
           ->execute([$min, $max, $cur, $per, $uid]);

        $db->prepare('DELETE FROM seeker_employment_types WHERE user_id = ?')->execute([$uid]);
        $ins = $db->prepare('INSERT INTO seeker_employment_types (user_id, employment_type) VALUES (?, ?)');
        foreach ($types as $t) {
            $ins->execute([$uid, $t]);
        }

        $db->prepare('DELETE FROM seeker_industries WHERE user_id = ?')->execute([$uid]);
        $ins = $db->prepare('INSERT INTO seeker_industries (user_id, industry) VALUES (?, ?)');
        foreach ($inds as $i) {
            $ins->execute([$uid, $i]);
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    return [];
}

function save_seeker_4(int $uid, array $p): array
{
    $errors = [];
    $cv = handle_upload('cv', 'cv', $errors);
    if ($errors) {
        return $errors;
    }
    if (!$cv && empty($p['cv_path'])) {
        return ['Upload your CV to finish.'];
    }
    if ($cv) {
        db()->prepare('UPDATE seeker_profiles SET cv_path = ? WHERE user_id = ?')->execute([$cv, $uid]);
        delete_upload($p['cv_path'] ?? null);
    }
    return [];
}

/* =====================================================================
   PAGE
   ===================================================================== */
[$stepTitle, $stepSub] = $steps[$step];
setup_top($role, $steps, $step, $reached);
?>
<div class="step-head">
    <p class="eyebrow">Step <?= $step ?> of <?= $total ?></p>
    <h1><?= e($stepTitle) ?></h1>
    <p class="subtitle"><?= e($stepSub) ?></p>
</div>

<?php show_errors($errors); ?>

<form method="post" enctype="multipart/form-data" class="setup-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="<?= $step ?>">

    <div class="grid">
    <?php if ($role === 'employer' && $step === 1): ?>
        <?= f_text('company_name', 'Company name', $v['company_name'] ?? '', ['required' => true, 'full' => true, 'attrs' => 'maxlength="150" placeholder="e.g. Nimbus Labs" autocomplete="organization"']) ?>
        <?= f_select('industry', 'Industry', $v['industry'] ?? '', INDUSTRIES, ['required' => true, 'full' => true]) ?>
        <?= f_file('logo', 'Company logo', 'image', $profile['logo_path'] ?? null, false) ?>

    <?php elseif ($role === 'employer' && $step === 2): ?>
        <?= f_text('city', 'City', $v['city'] ?? '', ['required' => true, 'attrs' => 'maxlength="100" placeholder="e.g. Ahmedabad" autocomplete="address-level2"']) ?>
        <?= f_text('country', 'Country', $v['country'] ?? '', ['required' => true, 'attrs' => 'maxlength="100" placeholder="e.g. India" autocomplete="country-name"']) ?>

    <?php elseif ($role === 'employer' && $step === 3): ?>
        <?= f_text('position', 'Position you are hiring for', $v['position'] ?? '', ['required' => true, 'full' => true, 'attrs' => 'maxlength="150" placeholder="e.g. Backend Developer"']) ?>
        <?= f_chips('employment_type', 'Employment type', $v['employment_type'] ?? '', EMPLOYMENT_TYPES, false) ?>
        <?= f_salary('Salary range', $v, 'salary_min', 'salary_max') ?>
        <?= f_textarea('job_detail', 'Job detail', $v['job_detail'] ?? '', ['attrs' => 'maxlength="5000" placeholder="What the person will do, the skills you need, perks…"']) ?>

    <?php elseif ($role === 'seeker' && $step === 1): ?>
        <?= f_text('full_name', 'Full name', $v['full_name'] ?? '', ['required' => true, 'full' => true, 'attrs' => 'maxlength="150" placeholder="As on your CV" autocomplete="name"']) ?>
        <?= f_text('date_of_birth', 'Date of birth', $v['date_of_birth'] ?? '', ['type' => 'date', 'attrs' => 'max="' . date('Y-m-d', strtotime('-16 years')) . '"']) ?>
        <?= f_text('nationality', 'Nationality', $v['nationality'] ?? '', ['attrs' => 'maxlength="100" placeholder="e.g. Indian"']) ?>
        <?= f_file('picture', 'Profile picture', 'image', $profile['picture_path'] ?? null, false) ?>

    <?php elseif ($role === 'seeker' && $step === 2): ?>
        <?= f_text('city', 'City', $v['city'] ?? '', ['required' => true, 'attrs' => 'maxlength="100" placeholder="e.g. Ahmedabad" autocomplete="address-level2"']) ?>
        <?= f_text('country', 'Country', $v['country'] ?? '', ['required' => true, 'attrs' => 'maxlength="100" placeholder="e.g. India" autocomplete="country-name"']) ?>
        <?= f_select('qualification', 'Highest qualification', $v['qualification'] ?? '', QUALIFICATIONS) ?>
        <?= f_text('experience_years', 'Years of experience', $v['experience_years'] ?? '0', ['type' => 'number', 'required' => true, 'attrs' => 'min="0" max="50" step="1"']) ?>

    <?php elseif ($role === 'seeker' && $step === 3): ?>
        <?= f_chips('employment_types', 'Employment type', $v['employment_types'] ?? [], EMPLOYMENT_TYPES, true, 'Pick all that you would accept.') ?>
        <?= f_chips('industries', 'Industries', $v['industries'] ?? [], INDUSTRIES, true, 'Pick up to 5.') ?>
        <?= f_salary('Expected salary', $v, 'expected_salary_min', 'expected_salary_max') ?>

    <?php elseif ($role === 'seeker' && $step === 4): ?>
        <?= f_file('cv', 'Your CV', 'cv', $profile['cv_path'] ?? null, true) ?>
    <?php endif; ?>
    </div>

    <div class="form-actions">
        <?php if ($step > 1): ?>
            <a class="btn-ghost" href="setup.php?step=<?= $step - 1 ?>">← Back</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <button type="submit" class="btn-primary"><?= $step === $total ? 'Finish setup' : 'Continue' ?></button>
    </div>
</form>
<?php setup_bottom();
