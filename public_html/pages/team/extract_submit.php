<?php
$user = require_team();
ensure_extract_schema();
$uid = (int) $user['id'];
$country = canonicalize_country_name(trim((string) (post('country') ?: get('country'))));
$raw = (string) (post('sites') ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'submit') {
    try {
        if ($country === '') {
            flash('error', 'Select a country.');
        } else {
            $parts = preg_split('/[\n,\t;]+/', $raw) ?: [];
            $res = extract_queue_add($parts, $country, $user);
            if ($res['inserted'] <= 0) {
                flash('error', 'No new sites added (empty list or all already in Block 1 for this country).');
            } else {
                flash('ok', 'Added ' . (int) $res['inserted'] . ' site(s) to Block 1 · ' . $country
                    . ($res['skipped'] > 0 ? ' · skipped ' . (int) $res['skipped'] . ' duplicates' : '') . '.');
                $raw = '';
            }
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=team_extract_submit&country=' . urlencode($country));
}

$queueCount = $country !== '' ? extract_queue_count($country) : extract_queue_count();
render_header('Submit for extraction', 'team');
?>
<div class="topbar">
  <div>
    <h1>Submit for extraction</h1>
    <p class="muted">Team 1 · paste sites into Block 1 (Need to be extracted), by country.</p>
  </div>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="submit">
  <label>Country</label>
  <?= render_country_select('country', $country, 'country', true) ?>
  <label style="margin-top:0.85rem">Sites <span class="help">(root domains, one per line)</span></label>
  <textarea name="sites" rows="14" required placeholder="example.com&#10;shop.de"><?= h($raw) ?></textarea>
  <p class="muted" style="margin-top:0.5rem">
    <?= $country !== '' ? h($country) . ' Block 1 currently has ' . (int) $queueCount . ' site(s).' : 'Pick a country to see the queue size.' ?>
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Add to Block 1</button>
  </p>
</form>
<?php render_footer('team'); ?>
