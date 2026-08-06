<?php
$user = require_team();
ensure_prospect_schema();
seed_countries_if_empty(db());

$frequent = user_frequent_countries((int) $user['id'], 8);
$raw = '';
$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$region = (string) (post('region') ?: get('region'));
$niche = '';
$notes = '';
$result = null;
$needsClean = false;
$old = ['domains' => [], 'total' => 0, 'truncated' => false];
$oldText = '';

// Default to the country this teammate uses most
if ($country === '' && $frequent !== []) {
    $country = (string) $frequent[0]['name'];
}

// Prefill language/region from country default
if ($country !== '' && ($language === '' || $region === '')) {
    foreach (list_countries(null, true) as $c) {
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
    // Box 1 = full database (all countries) for global uniqueness filtering
    $old = list_prospect_domain_names(100000, '');
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

        if ($country === '') {
            flash('error', 'Select a country first (new unique sites will be saved into that country).');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one site under “Paste new sites”.');
        } elseif ($action === 'clean') {
            $clean = clean_site_list($raw, '', true);
            $raw = $clean['text'];
            if ($clean['kept'] <= 0) {
                flash('error', clean_site_list_summary($clean) . ' Nothing left to filter.');
            } else {
                flash('ok', clean_site_list_summary($clean) . ' Review box ②, then Filter.');
            }
        } elseif ($action === 'add_new') {
            $clean = clean_site_list($raw, '', true);
            $raw = $clean['text'];
            $selected = $clean['domains'];
            if (!$selected) {
                flash('error', clean_site_list_summary($clean) . ' No new unique sites to add.');
            } else {
                $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
                $msg = 'Added ' . (int) $added['inserted'] . ' sites to ' . $country;
                if (!empty($added['batch_id'])) {
                    $msg .= ' · saved in today’s history';
                }
                if ((int) $clean['dup_db'] > 0 || (int) $added['skipped'] > 0) {
                    $msg .= ' · skipped duplicates already in Our database (any country)';
                }
                flash('ok', $msg . '.');
                $redir = 'index.php?page=team_prospect_check&country=' . urlencode($country);
                if (!empty($added['batch_id'])) {
                    $redir = 'index.php?page=team_prospect_batch&id=' . (int) $added['batch_id'];
                }
                redirect($redir);
            }
        } else {
            // Filter against the whole database; country is only the save destination
            $clean = clean_site_list($raw, '', false);
            $raw = $clean['text'];
            $domains = $clean['domains'];
            if (count($domains) > 100000) {
                flash('error', 'Paste at most 100,000 sites per run (split into batches).');
            } elseif (!$domains) {
                $needsClean = ((int) $clean['dropped'] > 0);
                flash('error', clean_site_list_summary($clean) . ' Nothing valid left — try Clean list or paste root domains.');
            } else {
                if ((int) $clean['dup_paste'] > 0 || (int) $clean['fixed'] > 0 || (int) $clean['dropped'] > 0) {
                    flash('ok', clean_site_list_summary($clean));
                }
                $result = filter_domains_against_prospects($domains, '');
                $old = list_prospect_domain_names(100000, '');
                $oldText = implode("\n", $old['domains']);
                if ($old['truncated']) {
                    $oldText .= "\n… +" . ($old['total'] - count($old['domains'])) . ' more (all used when filtering)';
                }
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
    <p class="muted">Type to search a country → paste → Filter against <strong>all countries</strong> → add only globally unique sites.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">Add history</a>
    <a class="btn secondary" href="index.php?page=team_prospects">Country folders</a>
  </div>
</div>
<?= guide_filter_add() ?>
<?= render_frequent_country_chips($frequent, 'index.php?page=team_prospect_check&country=') ?>

<ul class="steps">
  <li class="step <?= $stepPaste ?>"><span class="num">1</span> Country + paste</li>
  <li class="step <?= $stepFilter ?>"><span class="num">2</span> Filter</li>
  <li class="step <?= $stepAdd ?>"><span class="num">3</span> Add unique</li>
</ul>

<form method="post" id="filter_form">
  <input type="hidden" name="action" id="form_action" value="filter">

  <div class="card" style="margin-bottom:1rem">
    <div class="form-grid">
      <div>
        <label for="country_select">Save new sites into country <span class="help">(required · type to search)</span></label>
        <?= render_country_select('country', $country, 'country_select', true, $frequent) ?>
        <p class="help" style="margin-top:0.35rem">Filter checks the whole database. New unique sites are saved only into this country. Often-used countries appear first.</p>
      </div>
      <div>
        <label for="language_input">Language <span class="help">(optional · type to search)</span></label>
        <?= render_language_select('language', $language, 'language_input') ?>
      </div>
      <div>
        <label for="region_select">Region</label>
        <?= render_region_select('region', $region, 'region_select') ?>
      </div>
      <div><label>Niche</label><input name="niche" value="<?= h($niche) ?>"></div>
      <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= h($notes) ?></textarea></div>
    </div>
  </div>

  <div class="grid two-box">
    <div class="card box-panel panel-muted">
      <h2>① Already in Our database (all countries)</h2>
      <p class="help">
        <?= (int) $old['total'] ?> site<?= (int) $old['total'] === 1 ? '' : 's' ?> total · used to remove duplicates globally
        <?php if ($country !== '' && (int) $old['total'] > 0): ?>
          · <a href="index.php?page=team_prospects&amp;country=<?= urlencode($country) ?>&amp;export=txt">Download <?= h($country) ?> (.txt)</a>
        <?php endif; ?>
      </p>
      <textarea class="inventory-box" id="old_inventory" rows="14" readonly placeholder="Loading…"><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>② Paste new sites</h2>
      <p class="help">Root domains only. Duplicates already in Germany/Austria/etc. are removed before add.</p>
      <textarea class="inventory-box" id="domains" name="domains" rows="14" required
        placeholder="site1.com&#10;my-site.de&#10;shop.co.uk"><?= h($raw) ?></textarea>
    </div>
  </div>

  <div class="actions-sticky" style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
    <button class="btn secondary large" type="submit" style="max-width:280px"
      onclick="document.getElementById('form_action').value='clean'" <?= $country === '' ? 'disabled' : '' ?>>Clean list</button>
    <button class="btn large" type="submit" style="max-width:320px"
      onclick="document.getElementById('form_action').value='filter'" <?= $country === '' ? 'disabled' : '' ?>>Filter (all countries)</button>
  </div>
  <?php if ($needsClean): ?>
    <p class="help" style="text-align:center;margin-top:0.6rem"><strong>Tip:</strong> Click <em>Clean list</em> first, then Filter.</p>
  <?php endif; ?>
</form>

<script>
(function(){
  var sel = document.getElementById('country_select');
  var lang = document.getElementById('language_input');
  var region = document.getElementById('region_select');
  var btns = document.querySelectorAll('#filter_form button[type=submit]');
  if (!sel) return;
  function syncButtons(){
    btns.forEach(function(b){ b.disabled = !sel.value; });
  }
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    if (region && opt.dataset.region) region.value = opt.dataset.region;
    if (lang && opt.dataset.lang) lang.value = opt.dataset.lang || '';
    // Refresh searchable UI labels after programmatic set
    if (window.TechxSearchable) {
      region && region.dispatchEvent(new Event('change', { bubbles: true }));
      lang && lang.dispatchEvent(new Event('change', { bubbles: true }));
    }
    syncButtons();
    if (sel.value) {
      window.location = 'index.php?page=team_prospect_check&country=' + encodeURIComponent(sel.value);
    }
  });
  syncButtons();
})();
</script>

<?php if ($result): ?>
<div class="card">
  <h2>Results · <?= h($country) ?></h2>
  <p class="muted" style="margin:0">
    Pasted <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in database (any country) <strong><?= count($result['existing']) ?></strong> ·
    Unique <strong><?= count($result['new']) ?></strong>
    · will save into <strong><?= h($country) ?></strong>
  </p>
</div>

<div class="grid two-box">
  <div class="card panel-muted">
    <h2>Already in database (skipped)</h2>
    <?php if ($result['existing']): ?>
      <p class="help">These exist somewhere in Our database — each domain is allowed only once.</p>
      <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['existing'], 0, 5000))) ?><?= count($result['existing']) > 5000 ? "\n… +" . (count($result['existing']) - 5000) . ' more' : '' ?></textarea>
    <?php else: ?>
      <div class="empty-state"><p>Nothing skipped — none of these domains are in Our database yet.</p></div>
    <?php endif; ?>
  </div>
  <div class="card panel-ok">
    <h2>Unique — add into <?= h($country) ?></h2>
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
        <p class="help">Saves into <?= h($country) ?> and today’s history for <?= h($user['full_name'] ?: $user['username']) ?>. Already-known domains stay skipped.</p>
        <div class="actions-sticky">
          <button class="btn large block" type="submit">Add to <?= h($country) ?> (<?= count($result['new']) ?>)</button>
        </div>
      </form>
    <?php else: ?>
      <div class="empty-state">
        <p>No unique domains left — everything was already in Our database (any country).</p>
        <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Paste a new list</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
