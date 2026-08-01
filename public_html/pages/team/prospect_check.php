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
$added = null;

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
        // Re-filter from pasted text (avoids huge hidden-field POSTs on 10k–100k lists)
        $filter = filter_domains_against_prospects($domains);
        $selected = $filter['new'];
        $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
        flash('ok', "Added {$added['inserted']} unique prospect site(s). Skipped {$added['skipped']} already in prospect inventory.");
        redirect('index.php?page=team_prospects&country=' . urlencode($country) . '&language=' . urlencode($language));
    }

    // Default: filter only
    if (count($domains) > 100000) {
        flash('error', 'Please paste at most 100,000 domains per run (split into batches).');
    } elseif (!$domains) {
        flash('error', 'Paste at least one domain.');
    } else {
        $result = filter_domains_against_prospects($domains);
    }
}

render_header('Filter & add', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Prospects', 'href' => 'index.php?page=team_prospects'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Filter &amp; add prospects</h1>
    <p class="muted">Paste domains first. We filter against <strong>Prospects only</strong> (no prices). Only unique domains can be added.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_prospects">Prospects</a>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="filter">
  <label for="domains">Paste domains (one per line, or comma/space separated — up to 100,000)</label>
  <textarea id="domains" name="domains" rows="12" required placeholder="site1.com&#10;site2.de&#10;https://www.site3.com"><?= h($raw) ?></textarea>

  <div class="form-grid" style="margin-top:0.5rem">
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
    <button class="btn" type="submit">1. Filter unique vs Prospects</button>
  </p>
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
    Parsed: <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in prospects: <strong><?= count($result['existing']) ?></strong> ·
    New (unique): <strong><?= count($result['new']) ?></strong>
  </p>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <h2>Already in Prospects (do not re-add)</h2>
    <?php if ($result['existing']): ?>
      <textarea rows="14" readonly><?= h(implode("\n", $result['existing'])) ?></textarea>
    <?php else: ?>
      <p class="muted">None — all pasted domains are new to prospects.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>New — safe to add</h2>
    <?php if ($result['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="domains" value="<?= h($raw) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea rows="14" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">These will be saved into Prospects (no prices). Country/language from the form above are applied.</p>
        <p class="actions" style="margin-top:0.8rem">
          <button class="btn" type="submit">2. Add <?= count($result['new']) ?> unique site(s)</button>
        </p>
      </form>
    <?php else: ?>
      <p class="muted">No new domains — everything is already in Prospects.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
