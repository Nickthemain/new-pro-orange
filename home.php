<?php
// home.php — spec step 4: Home screen with swipe cards.
//   Seeker (5.2): sees job cards, swipes right (interested) or left (pass)
//   Employer (5.1): sees candidate cards for one of their jobs
//   Both say yes → match + video interview link
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/demo.php';

$user = require_app_user();
demo_auto();                                      // sample data appears by itself (DEMO_DATA in config.php)
$uid  = (int) $user['id'];
$me   = me($user);

$job  = null;
$jobs = [];
if ($user['role'] === 'employer') {
    ensure_first_job($uid);                          // setup details become job #1
    $jobs = employer_jobs($uid);
    $want = (int) ($_GET['job'] ?? 0);
    foreach ($jobs as $j) {
        if ((int) $j['id'] === $want) {
            $job = $j;
        }
    }
    $job ??= $jobs[0] ?? null;
    $cards = $job ? employer_feed($job) : [];
} else {
    $cards = seeker_feed($uid);
}

$stats   = swipe_stats($user);
$matches = user_matches($user, 4);
$isEmp   = $user['role'] === 'employer';

app_top($user, 'home', 'Discover', $me);
?>
<div class="discover">

  <section class="deck-col" aria-label="Cards">
    <div class="deck-head">
      <?php if ($isEmp && $job): ?>
        <div>
          <p class="eyebrow">Swiping candidates for</p>
          <?php if (count($jobs) > 1): ?>
            <form method="get" class="job-switch">
              <select name="job" class="input" onchange="this.form.submit()" aria-label="Choose job">
                <?php foreach ($jobs as $j): ?>
                  <option value="<?= (int) $j['id'] ?>" <?= $j['id'] == $job['id'] ? 'selected' : '' ?>><?= e($j['title']) ?></option>
                <?php endforeach; ?>
              </select>
              <noscript><button type="submit">Go</button></noscript>
            </form>
          <?php else: ?>
            <h1 class="deck-title"><?= e($job['title']) ?></h1>
          <?php endif; ?>
        </div>
        <span class="pill"><?= (int) $job['applicants'] ?> liked this job</span>
      <?php else: ?>
        <div>
          <p class="eyebrow">Jobs picked for you</p>
          <h1 class="deck-title">Discover</h1>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($isEmp && !$job): ?>
      <div class="empty">
        <h2>No open job yet</h2>
        <p>Your setup didn't include a position, so there is nothing to swipe for.</p>
      </div>
    <?php else: ?>
      <div class="stage">
        <div class="deck-stack" id="deck"
             data-role="<?= e($user['role']) ?>"
             data-job="<?= $job ? (int) $job['id'] : '' ?>"
             data-csrf="<?= e(csrf_token()) ?>"></div>

        <div class="empty" id="empty" hidden>
          <div class="empty-art" aria-hidden="true">✓</div>
          <h2>You're all caught up</h2>
          <p><?= $isEmp ? 'No more candidates for this job right now. New people join every day.'
                        : 'No more jobs for now. Check back soon, or see who matched with you.' ?></p>
          <a class="btn-primary inline-btn" href="matches.php">See matches</a>
        </div>
      </div>

      <div class="controls" id="controls">
        <button type="button" class="ctl pass" data-act="left" aria-label="Pass">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <button type="button" class="ctl like" data-act="right" aria-label="<?= $isEmp ? 'Interested in candidate' : 'Interested in job' ?>">
          <svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M12 21s-7.5-4.6-9.3-9.5C1.4 7.9 3.6 4.5 7.2 4.5c2 0 3.6 1.1 4.8 2.7 1.2-1.6 2.8-2.7 4.8-2.7 3.6 0 5.8 3.4 4.5 7C19.5 16.4 12 21 12 21Z"/></svg>
        </button>
      </div>
      <p class="kbd-hint">Drag the card, or use <kbd>←</kbd> pass · <kbd>→</kbd> interested</p>
    <?php endif; ?>
  </section>

  <aside class="side">
    <div class="side-card profile">
      <?= avatar($me['photo'], $me['name'], 'lg') ?>
      <div>
        <strong><?= e($me['name']) ?></strong>
        <small><?= $isEmp ? 'Employer' : 'Job seeker' ?> · <?= e($user['email']) ?></small>
      </div>
    </div>

    <div class="side-card">
      <ul class="stats">
        <li><strong id="stat-seen"><?= $stats['seen'] ?></strong><span>Swiped</span></li>
        <li><strong id="stat-liked"><?= $stats['liked'] ?></strong><span>Interested</span></li>
        <li><strong id="stat-matched"><?= $stats['matched'] ?></strong><span>Matches</span></li>
      </ul>
    </div>

    <div class="side-card">
      <div class="side-head">
        <h2>Recent matches</h2>
        <a href="matches.php">See all</a>
      </div>
      <?php if (!$matches): ?>
        <p class="muted-sm">No matches yet. When you and <?= $isEmp ? 'a candidate' : 'an employer' ?>
          both swipe right, they appear here with a video interview link.</p>
      <?php else: ?>
        <ul class="mini-list" id="recent-matches">
          <?php foreach ($matches as $m): ?>
            <li>
              <?= $isEmp ? avatar($m['picture_path'], $m['seeker_name'], 'sm') : avatar($m['logo_path'], $m['company_name'], 'sm') ?>
              <span class="mini-text">
                <strong><?= e($isEmp ? $m['seeker_name'] : $m['company_name']) ?></strong>
                <small><?= e($m['job_title']) ?></small>
              </span>
              <a class="mini-btn" href="<?= e($m['interview_video_link']) ?>" target="_blank" rel="noopener">Video</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="side-card how">
      <h2>How it works</h2>
      <ol>
        <li>Swipe right if you're interested, left to pass.</li>
        <li><?= $isEmp ? 'Candidates who liked your job show first.' : 'Jobs whose employer liked you show first.' ?></li>
        <li>Both swipe right → you match and get a video interview link.</li>
      </ol>
    </div>
  </aside>
</div>

<!-- "It's a match" popup -->
<div class="match-overlay" id="match" hidden>
  <div class="match-box" role="dialog" aria-modal="true" aria-labelledby="match-title">
    <div class="match-avatars" id="match-avatars"></div>
    <h2 id="match-title">It's a match!</h2>
    <p id="match-text"></p>
    <a class="btn-primary inline-btn" id="match-video" href="#" target="_blank" rel="noopener">Join video interview</a>
    <button type="button" class="btn-ghost" id="match-close">Keep swiping</button>
  </div>
</div>

<div class="toast" id="toast" role="status" hidden></div>

<script type="application/json" id="cards-data"><?= json_encode($cards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/swipe.js" defer></script>
<?php app_bottom();
