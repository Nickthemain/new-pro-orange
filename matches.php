<?php
// matches.php — everyone you matched with (both swiped right), with:
//   - a search box for a name or job title; suggestions are ranked by LOCATION and INDUSTRY only
//   - Location and Industry filters
//   - a Google Map showing where the selected match is
//   - likes still waiting for an answer
// Works without JavaScript (the form submits ?q=&loc=&ind=); assets/matches.js makes it live.
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/demo.php';

$user    = require_app_user();
demo_auto();                                       // sample data appears by itself (DEMO_DATA in config.php)
$me      = me($user);
$isEmp   = $user['role'] === 'employer';
$ctx     = viewer_context($user);
$rows    = match_rows($user);
$full    = array_column($rows, null, 'id');          // full rows (video link, CV) for the cards
$waiting = user_waiting($user);

// Filter options come from the matches themselves
$locations  = array_values(array_unique(array_filter(array_column($rows, 'city'))));
$industries = array_values(array_unique(array_merge(...array_column($rows, 'industries') ?: [[]])));
sort($locations);
sort($industries);

// Read the search (only accept values that exist in the lists)
$q   = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$loc = in_array($_GET['loc'] ?? '', $locations, true) ? $_GET['loc'] : '';
$ind = in_array($_GET['ind'] ?? '', $industries, true) ? $_GET['ind'] : '';

$results = search_matches($rows, $ctx, $q, $loc, $ind);
$shownIds = array_column($results, 'id');

// Map: the first result, or your own city when nothing matches
$focus    = $results[0] ?? null;
$mapPlace = $focus['place'] ?? $ctx['place'];

// Data for the live search script (same ranking rules as PHP)
foreach ($rows as &$r) {
    [$r['near'], $r['shared']] = relevance($r, $ctx);
    $r['map']  = map_embed_url($r['place']);
    $r['open'] = map_open_url($r['place']);
    unset($r['video'], $r['seeker_id'], $r['has_cv']);
}
unset($r);
$byId = array_column($rows, null, 'id');

app_top($user, 'matches', 'Matches', $me);
?>
<div class="page wide">
  <header class="page-head">
    <p class="eyebrow">Both sides said yes</p>
    <h1>Matches</h1>
    <p class="subtitle">Join the video call when you're ready. <?= $isEmp ? 'You can now open their CV.' : 'The employer can now see your CV.' ?></p>
  </header>

  <?php if (!$rows): ?>
    <div class="empty boxed">
      <h2>No matches yet</h2>
      <p>Keep swiping. A match happens when you and <?= $isEmp ? 'a candidate' : 'an employer' ?> both swipe right.</p>
      <a class="btn-primary inline-btn" href="home.php">Go to Discover</a>
    </div>
  <?php else: ?>
  <div class="m-layout">
    <div class="m-main">

      <form class="m-search" method="get" role="search" id="m-search" autocomplete="off">
        <div class="combo">
          <svg class="combo-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input type="search" name="q" id="q" value="<?= e($q) ?>" class="input"
                 placeholder="Search a <?= $isEmp ? 'candidate' : 'company' ?> name or job title"
                 role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="suggest"
                 aria-label="Search matches">
          <ul class="suggest" id="suggest" role="listbox" aria-label="Suggestions" hidden></ul>
        </div>

        <select name="loc" id="loc" class="input" aria-label="Location">
          <option value="">All locations</option>
          <?php foreach ($locations as $c): ?>
            <option value="<?= e($c) ?>" <?= $c === $loc ? 'selected' : '' ?>><?= e($c) ?><?= mb_strtolower($c) === mb_strtolower($ctx['city']) ? ' (near you)' : '' ?></option>
          <?php endforeach; ?>
        </select>

        <select name="ind" id="ind" class="input" aria-label="Industry">
          <option value="">All industries</option>
          <?php foreach ($industries as $i): ?>
            <option value="<?= e($i) ?>" <?= $i === $ind ? 'selected' : '' ?>><?= e($i) ?><?= in_array($i, $ctx['industries'], true) ? ' (yours)' : '' ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-primary search-go">Search</button>
      </form>

      <div class="m-status">
        <p id="m-count"><?= count($results) ?> of <?= count($rows) ?> matches</p>
        <a href="matches.php" id="m-clear" class="m-clear" <?= ($q || $loc || $ind) ? '' : 'hidden' ?>>Clear search</a>
      </div>

      <ul class="match-grid" id="match-list">
        <?php
        // results first (in ranked order), then the rest hidden so the live search can show them
        $order = array_merge($shownIds, array_diff(array_column($rows, 'id'), $shownIds));
        foreach ($order as $id):
            $r = $byId[$id];
            $m = $full[$id];
        ?>
          <li class="match-card<?= $focus && $focus['id'] === $id ? ' focused' : '' ?>" data-id="<?= $id ?>" <?= in_array($id, $shownIds, true) ? '' : 'hidden' ?>>
            <div class="match-top">
              <?= avatar($r['photo'], $r['name'], 'lg') ?>
              <div>
                <strong><?= e($r['name']) ?></strong>
                <small><?= e($r['title']) ?></small>
                <small><?= e(implode(' · ', array_filter([$r['place'], $r['detail']]))) ?></small>
              </div>
            </div>
            <div class="why">
              <?php if ($r['near']): ?><span class="why-chip near">📍 Near you</span><?php endif; ?>
              <?php foreach ($r['shared'] as $s): ?><span class="why-chip ind">Your industry: <?= e($s) ?></span><?php endforeach; ?>
              <span class="match-when">Matched <?= e($r['when']) ?></span>
            </div>
            <div class="match-actions">
              <a class="btn-primary inline-btn" href="<?= e($m['video']) ?>" target="_blank" rel="noopener">Join video interview</a>
              <?php if ($isEmp && $m['has_cv']): ?>
                <a class="btn-ghost bordered" href="cv.php?seeker=<?= $m['seeker_id'] ?>" target="_blank">View CV</a>
              <?php endif; ?>
              <a class="btn-ghost bordered map-btn" href="<?= e($r['open']) ?>" target="_blank" rel="noopener"
                 data-show-map="<?= $id ?>" aria-label="Show <?= e($r['name']) ?> on the map">Map</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="empty boxed" id="no-results" <?= $results ? 'hidden' : '' ?>>
        <h2>No matches found</h2>
        <p>Try another name, or clear the location and industry filters.</p>
        <a class="btn-primary inline-btn" href="matches.php">Clear search</a>
      </div>
    </div>

    <aside class="m-map" aria-label="Map">
      <div class="map-head">
        <div>
          <p class="eyebrow">Location</p>
          <strong id="map-name"><?= e($focus['name'] ?? 'You') ?></strong>
          <small id="map-place"><?= e($mapPlace ?: 'Location not set') ?></small>
        </div>
        <a id="map-open" class="mini-btn" href="<?= e(map_open_url($mapPlace)) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
      </div>
      <div class="map-frame">
        <iframe id="map" title="Google Map of <?= e($mapPlace) ?>" src="<?= e(map_embed_url($mapPlace)) ?>"
                loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
      </div>
      <?php if ($ctx['place']): ?>
        <button type="button" class="map-me" id="map-me" data-place="<?= e($ctx['place']) ?>"
                data-map="<?= e(map_embed_url($ctx['place'])) ?>" data-open="<?= e(map_open_url($ctx['place'])) ?>">
          Show my location (<?= e($ctx['place']) ?>)
        </button>
      <?php endif; ?>
    </aside>
  </div>
  <?php endif; ?>

  <section class="waiting">
    <h2>Waiting for a reply <span class="pill"><?= count($waiting) ?></span></h2>
    <?php if (!$waiting): ?>
      <p class="muted-sm">Nothing waiting. Everyone you liked has either matched or not seen you yet.</p>
    <?php else: ?>
      <ul class="mini-list wide">
        <?php foreach ($waiting as $w): ?>
          <li>
            <?= $isEmp ? avatar($w['picture_path'], $w['full_name'], 'sm') : avatar($w['logo_path'], $w['company_name'], 'sm') ?>
            <span class="mini-text">
              <strong><?= e($isEmp ? $w['full_name'] : $w['company_name']) ?></strong>
              <small><?= e($w['title']) ?> · liked <?= e(time_ago($w['created_at'])) ?></small>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php if ($rows): ?>
<script type="application/json" id="matches-data"><?= json_encode([
    'rows' => array_values($rows),
    'ctx'  => $ctx,
    'role' => $user['role'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="assets/matches.js" defer></script>
<?php endif; ?>
<?php app_bottom();
