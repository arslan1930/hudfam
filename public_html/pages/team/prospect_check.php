<?php
$user = require_team();
ensure_prospect_schema();
seed_countries_if_empty(db());

$countryOptions = list_countries(null, true);
$raw = '';
$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$region = (string) (post('region') ?: get('region'));
$niche = '';
$notes = '';
$result = null;
$old = ['domains' => [], 'total' => 0, 'truncated' => false];
$oldText = '';

// Prefill language/region from country default
if ($country !== '' && ($language === '' || $region === '')) {
    foreach ($countryOptions as $c) {
        if (strcasecmp((string) $c['name'], $country) === 0) {
            $country = (string) $c['name'];
            if ($region === '') {
                $region = (string) $c['region'];
            }
            if ($language === '' && $c['default_language'] !== '') {
                $language = (string) $c['default_language'];
            }
            break;
        }
    }
}

try {
    if ($country !== '') {
        $old = list_prospect_domain_names(25000, $country);
        $oldText = implode("\n", $old['domains']);
        if ($old['truncated']) {
            $oldText .= "\n… +" . ($old['total'] - count($old['domains'])) . ' more (all used when filtering)';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $raw = (string) post('domains');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));
        $region = (string) post('region');
        $niche = trim((string) post('niche'));
        $notes = trim((string) post('notes'));
        $parsed = parse_domain_list_strict($raw);
        $domains = $parsed['valid'];

        if ($country === '') {
            flash('error', 'Select a country database first (type to search, then Enter).');
        } elseif ($parsed['invalid_count'] > 0 && $action !== 'add_new') {
            flash('error', 'Remove invalid lines first (Clean errors). Root domains only — e.g. example.com or my-site.co.uk.');
            $raw = $parsed['valid_text'] !== ''
                ? $parsed['valid_text'] . "\n" . implode("\n", array_column($parsed['invalid'], 'raw'))
                : $raw;
        } elseif ($action === 'add_new') {
            $filter = filter_domains_against_prospects($domains, $country);
            $selected = $filter['new'];
            $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
            $msg = 'Added ' . (int) $added['inserted'] . ' sites to ' . $country;
            if (!empty($added['batch_id'])) {
                $msg .= ' · saved in today’s history';
            }
            if ((int) $added['skipped'] > 0) {
                $msg .= ' · Skipped ' . (int) $added['skipped'] . ' already in this country';
            }
            flash('ok', $msg . '.');
            $redir = 'index.php?page=team_prospect_check&country=' . urlencode($country);
            if (!empty($added['batch_id'])) {
                $redir = 'index.php?page=team_prospect_batch&id=' . (int) $added['batch_id'];
            }
            redirect($redir);
        } elseif (count($domains) > 100000) {
            flash('error', 'Paste at most 100,000 domains per run (split into batches).');
        } elseif (!$domains) {
            flash('error', 'Paste at least one root domain under “Paste new sites”.');
        } else {
            $result = filter_domains_against_prospects($domains, $country);
            $raw = implode("\n", $domains);
            // refresh box 1 after filter
            $old = list_prospect_domain_names(25000, $country);
            $oldText = implode("\n", $old['domains']);
            if ($old['truncated']) {
                $oldText .= "\n… +" . ($old['total'] - count($old['domains'])) . ' more (all used when filtering)';
            }
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
    <h1>Filter &amp; add<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Pick a country database → paste root domains → remove ones already in that country → add only unique sites.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">Add history</a>
    <a class="btn secondary" href="index.php?page=team_prospects">Country folders</a>
  </div>
</div>
<?= guide_filter_add() ?>

<ul class="steps">
  <li class="step <?= $stepPaste ?>"><span class="num">1</span> Country + paste</li>
  <li class="step <?= $stepFilter ?>"><span class="num">2</span> Filter</li>
  <li class="step <?= $stepAdd ?>"><span class="num">3</span> Add unique</li>
</ul>

<form method="post" id="filter_form">
  <input type="hidden" name="action" value="filter">

  <div class="card" style="margin-bottom:1rem">
    <div class="form-grid">
      <?= render_country_typeahead($country, [
          'id' => 'country',
          'label' => 'Country database',
          'attrs' => 'data-fill-language="[data-name=language]" data-fill-region="select[name=region]" data-reload-on-select="1"',
      ]) ?>
      <?= render_language_typeahead($language) ?>
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
  </div>

  <div class="grid two-box">
    <div class="card box-panel panel-muted">
      <h2>① Already in this country</h2>
      <p class="help">
        <?php if ($country === ''): ?>
          Select a country to load its database.
        <?php else: ?>
          <?= (int) $old['total'] ?> site<?= (int) $old['total'] === 1 ? '' : 's' ?> in <?= h($country) ?> · used to remove duplicates
        <?php endif; ?>
      </p>
      <textarea class="inventory-box" id="old_inventory" rows="14" readonly placeholder="Select a country first"><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>② Paste new sites</h2>
      <?= render_domains_paste_field('domains', $raw, [
          'id' => 'domains',
          'label' => 'Root domains',
          'required' => true,
          'rows' => 14,
          'class' => 'inventory-box',
      ]) ?>
    </div>
  </div>

  <div class="actions-sticky">
    <button class="btn large block" type="submit" style="max-width:420px;margin:0 auto;display:block" <?= $country === '' ? 'disabled' : '' ?> id="filter_submit">Filter against country</button>
  </div>
</form>

<script>
(function(){
  var form = document.getElementById('filter_form');
  var btn = document.getElementById('filter_submit');
  var countryRoot = form && form.querySelector('[data-name="country"]');
  if (!countryRoot) return;
  function syncBtn() {
    var hidden = countryRoot.querySelector('[data-typeahead-value]');
    if (btn) btn.disabled = !(hidden && hidden.value);
  }
  countryRoot.addEventListener('typeahead:select', function(e){
    syncBtn();
    if (countryRoot.getAttribute('data-reload-on-select') === '1' && e.detail && e.detail.value) {
      window.location = 'index.php?page=team_prospect_check&country=' + encodeURIComponent(e.detail.value);
    }
  });
  syncBtn();
})();
</script>
<?= sites_form_script_tag() ?>

<?php if ($result): ?>
<div class="card">
  <h2>Results · <?= h($country) ?></h2>
  <p class="muted" style="margin:0">
    Pasted <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in this country <strong><?= count($result['existing']) ?></strong> ·
    Unique <strong><?= count($result['new']) ?></strong>
  </p>
</div>

<div class="grid two-box">
  <div class="card panel-muted">
    <h2>Already known (skipped)</h2>
    <?php if ($result['existing']): ?>
      <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['existing'], 0, 5000))) ?><?= count($result['existing']) > 5000 ? "\n… +" . (count($result['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <div class="empty-state"><p>Nothing skipped — all pasted domains are new for this country.</p></div>
    <?php endif; ?>
  </div>
  <div class="card panel-ok">
    <h2>Unique — add these</h2>
    <?php if ($result['new']): ?>
      <form method="post">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="domains" value="<?= h(implode("\n", $result['new'])) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">Saves to <?= h($country) ?> and today’s history for <?= h($user['full_name'] ?: $user['username']) ?>.</p>
        <div class="actions-sticky">
          <button class="btn large block" type="submit">Add to <?= h($country) ?> (<?= count($result['new']) ?>)</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state">
        <p>No unique domains left — everything was already in <?= h($country) ?>.</p>
        <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Paste a new list</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
