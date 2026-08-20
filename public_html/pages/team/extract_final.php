<?php
$user = require_team();
ensure_extract_schema();
$country = canonicalize_country_name(trim((string) (post('country') ?: get('country'))));
$raw = (string) (post('sites') ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    try {
        if ($country === '') {
            flash('error', 'Select a country.');
        } else {
            $parts = preg_split('/[\n,\t;]+/', $raw) ?: [];
            $res = extract_sites_add($parts, $country, $user);
            if ($res['inserted'] <= 0) {
                flash('error', 'No new sites saved (empty or already in Block 2 for this country).');
            } else {
                flash('ok', 'Saved ' . (int) $res['inserted'] . ' extracted site(s) to Block 2 · ' . $country
                    . ($res['skipped'] > 0 ? ' · skipped ' . (int) $res['skipped'] . ' duplicates' : '') . '.');
                $raw = '';
            }
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('index.php?page=team_extract_final&country=' . urlencode($country));
}

$count = $country !== '' ? extract_sites_count($country) : 0;
render_header('Paste extracted', 'team');
?>
<div class="topbar">
  <div>
    <h1>Paste extracted (Block 2)</h1>
    <p class="muted">Team 2 · after extraction, paste the final site list here. This feeds Extracted sites with Emails.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_extract_emails<?= $country !== '' ? '&country=' . urlencode($country) : '' ?>">Add emails</a>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="save">
  <label>Country</label>
  <?= render_country_select('country', $country, 'country', true) ?>
  <label style="margin-top:0.85rem">Final extracted sites <span class="help">(root domains)</span></label>
  <textarea name="sites" rows="14" required placeholder="clean-example.com"><?= h($raw) ?></textarea>
  <p class="muted" style="margin-top:0.5rem">
    <?= $country !== '' ? h($country) . ' Block 2 currently has ' . (int) $count . ' site(s).' : '' ?>
  </p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save to Block 2</button>
  </p>
</form>
<?php render_footer('team'); ?>
