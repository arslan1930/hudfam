<?php
$user = require_team();
ensure_prospect_schema();
seed_countries_if_empty(db());

$countryOptions = list_countries(null, true);
$countryGroups = countries_grouped();
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
        $old = list_prospect_domain_names(100000, $country);
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

        if ($country === '') {
            flash('error', 'Select a country database first.');
        } elseif (trim($raw) === '') {
            flash('error', 'Paste at least one site under “Paste new sites”.');
        } elseif ($action === 'clean') {
            $clean = clean_site_list($raw, $country, true);
            $raw = $clean['text'];
            if ($clean['kept'] <= 0) {
                flash('error', clean_site_list_summary($clean) . ' Nothing left to filter.');
            } else {
                flash('ok', clean_site_list_summary($clean) . ' Review box ②, then Filter.');
            }
        } elseif ($action === 'add_new') {
            $clean = clean_site_list($raw, $country, true);
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
                    $msg .= ' · removed duplicates already in database';
                }
                flash('ok', $msg . '.');
                $redir = 'index.php?page=team_prospect_check&country=' . urlencode($country);
                if (!empty($added['batch_id'])) {
                    $redir = 'index.php?page=team_prospect_batch&id=' . (int) $added['batch_id'];
                }
                redirect($redir);
            }
        } else {
            // Filter: auto-clean bad lines / paste dups, then compare to country DB
            $clean = clean_site_list($raw, $country, false); // keep DB dups so Filter can show them
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
                $result = filter_domains_against_prospects($domains, $country);
                $old = list_prospect_domain_names(100000, $country);
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
    <p class="muted">Paste sites → <strong>Clean list</strong> (fix errors &amp; drop duplicates) → Filter → Add unique.</p>
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
  <input type="hidden" name="action" id="form_action" value="filter">

  <div class="card" style="margin-bottom:1rem">
    <div class="form-grid">
      <div>
        <label for="country_select">Country database <span class="help">(required)</span></label>
        <select name="country" id="country_select" required>
          <option value="">— Select country —</option>
          <?php foreach ($countryGroups as $regionCode => $block): ?>
            <?php if (empty($block['countries'])) {
                continue;
            } ?>
            <optgroup label="<?= h($block['label']) ?>">
              <?php foreach ($block['countries'] as $c): ?>
                <option value="<?= h($c['name']) ?>"
                  data-region="<?= h($c['region']) ?>"
                  data-lang="<?= h($c['default_language']) ?>"
                  <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="language_input">Language <span class="help">(optional)</span></label>
        <?= render_language_select('language', $language, 'language_input') ?>
      </div>
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
          <?php if ((int) $old['total'] > 0): ?>
            · <a href="index.php?page=team_prospects&amp;country=<?= urlencode($country) ?>&amp;export=txt">Download all (.txt)</a>
            · <a href="index.php?page=team_prospects&amp;country=<?= urlencode($country) ?>&amp;view=names">View all names</a>
          <?php endif; ?>
        <?php endif; ?>
      </p>
      <textarea class="inventory-box" id="old_inventory" rows="14" readonly placeholder="Select a country first"><?= h($oldText) ?></textarea>
    </div>
    <div class="card box-panel">
      <h2>② Paste new sites</h2>
      <p class="help">Root domains: <code>example.com</code>, <code>example.co.uk</code>. Use Clean list if you pasted https://, www., paths, or duplicates.</p>
      <textarea class="inventory-box" id="domains" name="domains" rows="14" required
        placeholder="site1.com&#10;my-site.de&#10;shop.co.uk"><?= h($raw) ?></textarea>
    </div>
  </div>

  <div class="actions-sticky" style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
    <button class="btn secondary large" type="submit" style="max-width:280px"
      onclick="document.getElementById('form_action').value='clean'" <?= $country === '' ? 'disabled' : '' ?>>Clean list</button>
    <button class="btn large" type="submit" style="max-width:320px"
      onclick="document.getElementById('form_action').value='filter'" <?= $country === '' ? 'disabled' : '' ?>>Filter against country</button>
  </div>
  <?php if ($needsClean): ?>
    <p class="help" style="text-align:center;margin-top:0.6rem"><strong>Tip:</strong> Click <em>Clean list</em> first, then Filter.</p>
  <?php endif; ?>
</form>

<script>
(function(){
  var sel = document.getElementById('country_select');
  var lang = document.getElementById('language_input');
  var region = document.querySelector('select[name=region]');
  var btns = document.querySelectorAll('#filter_form button[type=submit]');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    if (region && opt.dataset.region) region.value = opt.dataset.region;
    if (lang && opt.dataset.lang) lang.value = opt.dataset.lang || '';
    btns.forEach(function(b){ b.disabled = !sel.value; });
    if (sel.value) {
      window.location = 'index.php?page=team_prospect_check&country=' + encodeURIComponent(sel.value);
    }
  });
})();
</script>

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
        <p class="help">Saves to <?= h($country) ?> and today’s history for <?= h($user['full_name'] ?: $user['username']) ?>. Duplicates are removed automatically.</p>
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
