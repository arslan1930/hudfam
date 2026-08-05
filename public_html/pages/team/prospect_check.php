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
            $msg = 'Added ' . (int) $added['inserted'] . ' sites to Our database';
            if (!empty($added['batch_id'])) {
                $msg .= ' · saved in today’s history';
            }
            if ((int) $added['skipped'] > 0) {
                $msg .= ' · Skipped ' . (int) $added['skipped'] . ' already known';
            }
            flash('ok', $msg . '.');
            $redir = 'index.php?page=team_prospect_check';
            if (!empty($added['batch_id'])) {
                $redir = 'index.php?page=team_prospect_batch&id=' . (int) $added['batch_id'];
            }
            redirect($redir);
        }

        if (count($domains) > 100000) {
            flash('error', 'Paste at most 100,000 domains per run (split into batches).');
        } elseif (!$domains) {
            flash('error', 'Paste at least one domain under “Paste new sites”.');
        } else {
            $result = filter_domains_against_prospects($domains);
        }
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then try Filter again.');
}

$stepPaste = !$result ? 'active' : 'done';
$stepFilter = $result ? 'active' : '';
$stepAdd = ($result && $result['new']) ? 'active' : '';

render_header('Filter & add', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Our database', 'href' => 'index.php?page=team_prospects'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Filter &amp; add</h1>
    <p class="muted">Paste domains → remove ones already in Our database → add only unique sites.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">Add history</a>
    <a class="btn secondary" href="index.php?page=team_prospects">Our database</a>
  </div>
</div>
<?= guide_filter_add() ?>

<ul class="steps">
  <li class="step <?= $stepPaste ?>"><span class="num">1</span> Paste new</li>
  <li class="step <?= $stepFilter ?>"><span class="num">2</span> Filter</li>
  <li class="step <?= $stepAdd ?>"><span class="num">3</span> Add unique</li>
</ul>

<form method="post" id="filter_form">
  <input type="hidden" name="action" value="filter">

  <div class="grid two-box">
    <div class="card box-panel panel-muted">
      <h2>① Already in Our database</h2>
      <p class="help"><?= (int) $old['total'] ?> site names · used to remove duplicates</p>
      <textarea class="inventory-box" id="old_inventory" rows="14" readonly placeholder="No sites yet"><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>② Paste new sites</h2>
      <p class="help">One per line (or commas). https:// is removed automatically.</p>
      <textarea class="inventory-box" id="domains" name="domains" rows="14" required
        placeholder="site1.com&#10;site2.de&#10;https://www.site3.com"><?= h($raw) ?></textarea>
    </div>
  </div>

  <div class="actions-sticky">
    <button class="btn large block" type="submit" style="max-width:420px;margin:0 auto;display:block">Filter sites</button>
  </div>

  <details class="card" style="margin-top:1rem" <?= ($country || $language || $niche || $notes) ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:700">Details for new sites (country, language…)</summary>
    <div class="form-grid" style="margin-top:0.6rem">
      <div><label>Country</label>
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
  </details>
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
  <h2>Results</h2>
  <p class="muted" style="margin:0">
    Pasted <strong><?= (int) $result['total_input'] ?></strong> ·
    Already known <strong><?= count($result['existing']) ?></strong> ·
    Unique <strong><?= count($result['new']) ?></strong>
  </p>
</div>

<div class="grid two-box">
  <div class="card panel-muted">
    <h2>Already known (skipped)</h2>
    <?php if ($result['existing']): ?>
      <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['existing'], 0, 5000))) ?><?= count($result['existing']) > 5000 ? "\n… +" . (count($result['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <div class="empty-state"><p>Nothing skipped — all pasted domains are new.</p></div>
    <?php endif; ?>
  </div>
  <div class="card panel-ok">
    <h2>Unique — add these</h2>
    <?php if ($result['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="domains" value="<?= h($raw) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">Saves to Our database and today’s history for <?= h($user['full_name'] ?: $user['username']) ?>.</p>
        <div class="actions-sticky">
          <button class="btn large block" type="submit">Add sites (<?= count($result['new']) ?>)</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state">
        <p>No unique domains left — everything was already in Our database.</p>
        <a class="btn secondary" href="index.php?page=team_prospect_check">Paste a new list</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
