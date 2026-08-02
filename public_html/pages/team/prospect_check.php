<?php
$user = require_team();
$countryOptions = list_countries(null, true);
$raw = '';
$country = '';
$language = '';
$region = '';
$niche = '';
$notes = '';
$result = null;
$old = ['domains' => [], 'total' => 0, 'truncated' => false];
$oldText = '';

try {
    $old = list_prospect_domain_names(25000);
    $oldText = implode("\n", $old['domains']);
    if ($old['truncated']) {
        $oldText .= "\n… +" . ($old['total'] - count($old['domains'])) . ' more (all used when filtering)';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $raw = (string) post('domains');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));
        $region = (string) post('region');
        $niche = trim((string) post('niche'));
        $notes = trim((string) post('notes'));
        $domains = parse_domain_list($raw);

        if ($action === 'add_new') {
            $filter = filter_domains_against_prospects($domains);
            $selected = $filter['new'];
            $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
            $msg = "Added {$added['inserted']} unique site(s) to Our inventory";
            if (!empty($added['batch_id'])) {
                $msg .= " and today’s batch (#{$added['batch_id']})";
            }
            $msg .= ". Skipped {$added['skipped']} already known.";
            flash('ok', $msg);
            $redir = 'index.php?page=team_prospect_check';
            if (!empty($added['batch_id'])) {
                $redir = 'index.php?page=team_prospect_batch&id=' . (int) $added['batch_id'];
            }
            redirect($redir);
        }

        if (count($domains) > 100000) {
            flash('error', 'Please paste at most 100,000 domains per run (split into batches).');
        } elseif (!$domains) {
            flash('error', 'Paste at least one domain in Box 2.');
        } else {
            $result = filter_domains_against_prospects($domains);
        }
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then try Filter again.');
}

render_header('Filter & add', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Our inventory', 'href' => 'index.php?page=team_prospects'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Filter & add prospect sites</h1>
    <p class="muted">
      Box 1 = <strong>Our inventory</strong> (Admin master list). Box 2 = new sites to check.
      Filter removes inventory domains from new → only <strong>unique</strong> sites can be added.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">Dated batches</a>
    <a class="btn secondary" href="index.php?page=team_prospects">Full prospect list</a>
  </div>
</div>

<form method="post" id="filter_form">
  <input type="hidden" name="action" value="filter">

  <div class="grid two-box">
    <div class="card box-panel">
      <h2>Box 1 — Our inventory</h2>
      <p class="help"><?= (int) $old['total'] ?> site name(s) · Admin master list · used for unique filter</p>
      <textarea class="inventory-box" id="old_inventory" rows="16" readonly><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>Box 2 — New sites to filter</h2>
      <p class="help">Paste domains (one per line or comma/space). https:// is stripped automatically.</p>
      <textarea class="inventory-box" id="domains" name="domains" rows="16" required
        placeholder="site1.com&#10;site2.de&#10;https://www.site3.com"><?= h($raw) ?></textarea>
    </div>
  </div>

  <div class="card">
    <div class="form-grid">
      <div><label>Country (for new sites)</label>
        <select name="country" id="country_select">
          <option value="">—</option>
          <?php foreach ($countryOptions as $c): ?>
            <option value="<?= h($c['name']) ?>"
              data-region="<?= h($c['region']) ?>"
              data-lang="<?= h($c['default_language']) ?>"
              <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Language</label><input name="language" id="language_input" value="<?= h($language) ?>"></div>
      <div><label>Region</label>
        <select name="region">
          <option value="">—</option>
          <?php foreach (regions() as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Niche</label><input name="niche" value="<?= h($niche) ?>"></div>
      <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= h($notes) ?></textarea></div>
    </div>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Filter sites</button>
    </p>
  </div>
</form>

<script>
(function(){
  var sel = document.getElementById('country_select');
  var lang = document.getElementById('language_input');
  var region = document.querySelector('select[name=region]');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    if (region && opt.dataset.region) region.value = opt.dataset.region;
    if (lang && opt.dataset.lang && !lang.value) lang.value = opt.dataset.lang;
  });
})();
</script>

<?php if ($result): ?>
<div class="card">
  <h2>Filter results</h2>
  <p>
    From Box 2: <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in Box 1 (excluded): <strong><?= count($result['existing']) ?></strong> ·
    Unique (new): <strong><?= count($result['new']) ?></strong>
  </p>
</div>

<div class="grid two-box">
  <div class="card">
    <h2>Excluded — already in Our inventory</h2>
    <?php if ($result['existing']): ?>
      <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($result['existing'], 0, 5000))) ?><?= count($result['existing']) > 5000 ? "\n… +" . (count($result['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <p class="muted">None excluded — all pasted domains are new.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Unique results — ready to add</h2>
    <?php if ($result['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="domains" value="<?= h($raw) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea class="inventory-box" rows="12" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">Add sites → writes to <strong>Our inventory (Box 1)</strong> and <strong>today’s dated batch</strong> for <?= h($user['full_name'] ?: $user['username']) ?>.</p>
        <p class="actions" style="margin-top:0.8rem">
          <button class="btn" type="submit">Add sites (<?= count($result['new']) ?>)</button>
        </p>
      </form>
    <?php else: ?>
      <p class="muted">No unique domains left — everything was already in Box 1.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
