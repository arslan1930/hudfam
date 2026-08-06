<?php
$user = require_team();
ensure_prospect_schema();
seed_countries_if_empty(db());

$uid = (int) $user['id'];
$frequent = user_frequent_countries($uid, 8);
$raw = '';
$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$region = (string) (post('region') ?: get('region'));
$niche = '';
$notes = '';
$result = null;
$needsClean = false;
$old = ['domains' => [], 'total' => 0, 'truncated' => false];
$oldPreview = [];
$oldMore = 0;

// Default to the country this teammate uses most / first open task
if ($country === '' && $frequent !== []) {
    $country = (string) $frequent[0]['name'];
}
if ($country === '') {
    try {
        $tasks = list_team_tasks($uid, 'open', 1);
        if ($tasks && trim((string) ($tasks[0]['country'] ?? '')) !== '') {
            $country = (string) $tasks[0]['country'];
        }
    } catch (Throwable $e) {
        // ignore
    }
}
if ($country !== '') {
    $country = canonicalize_country_name($country);
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
    // Team only sees a tiny uncopyable preview — filter still checks the full DB server-side
    $previewLimit = 8;
    $old = list_prospect_domain_names($previewLimit, '');
    $oldPreview = array_slice($old['domains'], 0, $previewLimit);
    $oldMore = max(0, (int) $old['total'] - count($oldPreview));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');

        if ($action === 'clear_today_list') {
            $res = clear_team_today_copy_list($uid);
            if ($res['ok']) {
                flash('ok', 'Cleared ' . (int) $res['count'] . ' site(s) from your copy list. Sites stay saved — use Undo if this was a mistake.');
            } else {
                flash('error', $res['error'] ?: 'Could not clear.');
            }
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }

        if ($action === 'undo_clear_today_list') {
            $res = undo_team_today_copy_clear($uid);
            if ($res['ok']) {
                flash('ok', 'Undo complete — restored ' . (int) $res['count'] . ' site(s) to your copy list.');
            } else {
                flash('error', $res['error'] ?: 'Nothing to undo.');
            }
            redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
        }

        $raw = (string) post('domains');
        $country = canonicalize_country_name(trim((string) post('country')));
        $language = trim((string) post('language'));
        $region = (string) post('region');
        $niche = trim((string) post('niche'));
        $notes = trim((string) post('notes'));

        if ($country === '') {
            flash('error', 'Select a country first (new unique sites will be saved into that country).');
        } elseif (trim($raw) === '' && !in_array($action, ['clear_today_list', 'undo_clear_today_list'], true)) {
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
                $tldGate = analyze_country_tld_match($selected, $country);
                $acked = post('confirm_tld_mismatch') === '1';
                if ($tldGate['warn'] && !$acked) {
                    flash('error', 'Country/TLD mismatch warning: confirm the checkbox before adding, or change the country.');
                    $result = [
                        'existing' => [],
                        'new' => $selected,
                        'invalid' => 0,
                        'total_input' => count($selected),
                    ];
                    $tldCheck = $tldGate;
                } else {
                    $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
                    $msg = 'Added ' . (int) $added['inserted'] . ' sites to ' . $country;
                    if ((int) $clean['dup_db'] > 0 || (int) $added['skipped'] > 0) {
                        $msg .= ' · skipped duplicates already in database';
                    }
                    if ($tldGate['warn']) {
                        $msg .= ' · saved despite TLD mismatch warning';
                    }
                    flash('ok', $msg . '. Copy list updated below.');
                    redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
                }
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
                // Keep tiny preview only (do not reload full inventory into the page)
                $old = list_prospect_domain_names($previewLimit, '');
                $oldPreview = array_slice($old['domains'], 0, $previewLimit);
                $oldMore = max(0, (int) $old['total'] - count($oldPreview));
            }
        }
    }
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then try Filter again.');
}

$todayCopy = team_today_new_sites_for_copy($uid);
$todayText = implode("\n", $todayCopy['domains']);

$tldCheck = [
    'warn' => false,
    'message' => '',
    'match_pct' => 100.0,
    'signal' => 0,
    'matched' => 0,
    'expected' => [],
    'top_tlds' => [],
    'dominant_tld' => '',
];
if ($result && !empty($result['new'])) {
    $tldCheck = analyze_country_tld_match($result['new'], $country);
}

$stepPaste = !$result ? 'active' : 'done';
$stepFilter = $result ? 'active' : '';
$stepAdd = ($result && $result['new']) ? 'active' : '';

render_header('Filter & add', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Filter &amp; add<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Paste → Filter → add unique sites. Your new sites for today appear at the bottom to copy.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_batches">Added sites</a>
  </div>
</div>
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
        <p class="help" style="margin-top:0.35rem">Filter checks the whole database. New unique sites are saved only into this country.</p>
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
      <h2>① Already in database</h2>
      <p class="help">
        <?= (int) $old['total'] ?> site<?= (int) $old['total'] === 1 ? '' : 's' ?> total · preview only (not copyable). Filter still checks everything.
      </p>
      <div class="db-preview" aria-label="Database preview (not copyable)" oncopy="return false" oncut="return false" oncontextmenu="return false">
        <?php if ($oldPreview === []): ?>
          <p class="muted" style="margin:0">No sites in the database yet.</p>
        <?php else: ?>
          <ul class="db-preview-list">
            <?php foreach ($oldPreview as $d): ?>
              <li><?= h($d) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($oldMore > 0): ?>
            <p class="db-preview-more">… and <?= (int) $oldMore ?> more (hidden)</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="card box-panel">
      <h2>② Paste new sites</h2>
      <p class="help">Root domains only. Duplicates already in the database are removed before add.</p>
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
document.querySelectorAll('.db-preview').forEach(function(el){
  el.addEventListener('selectstart', function(e){ e.preventDefault(); });
  el.addEventListener('dragstart', function(e){ e.preventDefault(); });
});
</script>

<?php if ($result): ?>
<div class="card">
  <h2>Results · <?= h($country) ?></h2>
  <p class="muted" style="margin:0">
    Pasted <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in database <strong><?= count($result['existing']) ?></strong> ·
    Unique <strong><?= count($result['new']) ?></strong>
    · will save into <strong><?= h($country) ?></strong>
  </p>
</div>

<?php if (!empty($tldCheck['warn'])): ?>
<div class="card tld-warn" role="alert">
  <h2 style="margin:0 0 0.4rem;color:var(--warn)">Possible wrong country</h2>
  <p style="margin:0"><?= h($tldCheck['message']) ?></p>
  <p class="help" style="margin:0.55rem 0 0">
    Soft check only — .com/.net/.org/.eu are ignored.
    Expected for <?= h($country) ?>:
    <?php
      $exp = array_slice($tldCheck['expected'] ?? [], 0, 6);
      echo h(implode(', ', array_map(static fn($t) => '.' . $t, $exp)));
    ?>.
    Match on country-specific TLDs: <strong><?= (int) $tldCheck['match_pct'] ?>%</strong>
    (<?= (int) $tldCheck['matched'] ?>/<?= (int) $tldCheck['signal'] ?>).
  </p>
</div>
<?php endif; ?>

<div class="grid two-box">
  <div class="card panel-muted">
    <h2>Already in database (skipped)</h2>
    <?php if ($result['existing']): ?>
      <?php
        $skipPreview = array_slice($result['existing'], 0, 8);
        $skipMore = count($result['existing']) - count($skipPreview);
      ?>
      <p class="help"><?= count($result['existing']) ?> already known — preview only (not copyable).</p>
      <div class="db-preview" aria-label="Skipped domains preview" oncopy="return false" oncut="return false" oncontextmenu="return false">
        <ul class="db-preview-list">
          <?php foreach ($skipPreview as $d): ?>
            <li><?= h($d) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($skipMore > 0): ?>
          <p class="db-preview-more">… and <?= (int) $skipMore ?> more skipped (hidden)</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="empty-state"><p>Nothing skipped — none of these domains are in the database yet.</p></div>
    <?php endif; ?>
  </div>
  <div class="card panel-ok">
    <h2>Unique — add into <?= h($country) ?></h2>
    <?php if ($result['new']): ?>
      <form method="post" id="add_unique_form">
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="domains" value="<?= h(implode("\n", $result['new'])) ?>">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <input type="hidden" name="notes" value="<?= h($notes) ?>">
        <textarea class="inventory-box" rows="10" readonly><?= h(implode("\n", array_slice($result['new'], 0, 5000))) ?><?= count($result['new']) > 5000 ? "\n… +" . (count($result['new']) - 5000) . ' more' : '' ?></textarea>
        <p class="help">Saves into <?= h($country) ?>. They also appear in your copy list below.</p>
        <?php if (!empty($tldCheck['warn'])): ?>
          <label class="tld-confirm">
            <input type="checkbox" name="confirm_tld_mismatch" value="1" required>
            I confirm these sites belong in <strong><?= h($country) ?></strong> (or I accept saving them there anyway).
          </label>
        <?php endif; ?>
        <div class="actions-sticky">
          <button class="btn large block" type="submit" id="add_unique_btn">
            Add to <?= h($country) ?> (<?= count($result['new']) ?>)
          </button>
        </div>
      </form>
      <?php if (!empty($tldCheck['warn'])): ?>
      <script>
      (function(){
        var form = document.getElementById('add_unique_form');
        if (!form) return;
        form.addEventListener('submit', function(e){
          var box = form.querySelector('input[name=confirm_tld_mismatch]');
          if (box && !box.checked) {
            e.preventDefault();
            alert('Please confirm the country/TLD warning checkbox first, or change the country.');
            return;
          }
          if (!confirm(<?= json_encode('Save ' . count($result['new']) . ' site(s) into ' . $country . ' despite the TLD mismatch warning?', JSON_UNESCAPED_UNICODE) ?>)) {
            e.preventDefault();
          }
        });
      })();
      </script>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty-state">
        <p>No unique domains left — everything was already in the database.</p>
        <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Paste a new list</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" id="today_copy_list" style="margin-top:1.25rem">
  <div class="topbar" style="margin:0;padding:0;border:0">
    <div>
      <h2 style="margin:0">My new sites today (copy)</h2>
      <p class="muted" style="margin:0.35rem 0 0">
        <?= count($todayCopy['domains']) ?> site<?= count($todayCopy['domains']) === 1 ? '' : 's' ?> in this list
        <?php if ((int) $todayCopy['total_today'] > count($todayCopy['domains'])): ?>
          · <?= (int) $todayCopy['total_today'] ?> added today total
        <?php endif; ?>
        · select all / copy as needed. Clear when you start a fresh list (sites stay saved).
      </p>
    </div>
    <div class="actions">
      <?php if ($todayCopy['can_undo']): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="undo_clear_today_list">
          <input type="hidden" name="country" value="<?= h($country) ?>">
          <input type="hidden" name="domains" value="">
          <button class="btn secondary" type="submit">Undo clear</button>
        </form>
      <?php endif; ?>
      <?php if ($todayCopy['domains'] !== []): ?>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= h(json_encode('Clear your copy list of ' . count($todayCopy['domains']) . ' site(s)? Sites stay in the database. You can Undo after.', JSON_UNESCAPED_UNICODE)) ?>);">
          <input type="hidden" name="action" value="clear_today_list">
          <input type="hidden" name="country" value="<?= h($country) ?>">
          <input type="hidden" name="domains" value="">
          <button class="btn secondary" type="submit">Clear list</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($todayCopy['domains'] === []): ?>
    <div class="empty-state" style="margin-top:0.75rem">
      <p><?= $todayCopy['cleared'] ? 'List cleared. Add more sites, or Undo clear.' : 'No new sites in the copy list yet — add unique sites above.' ?></p>
    </div>
  <?php else: ?>
    <textarea class="inventory-box" id="today_sites_copy" rows="12" readonly style="margin-top:0.85rem"><?= h($todayText) ?></textarea>
    <p class="actions" style="margin-top:0.75rem">
      <button class="btn secondary" type="button" onclick="(function(){var t=document.getElementById('today_sites_copy');t.focus();t.select();try{document.execCommand('copy');}catch(e){} })();">Copy all</button>
    </p>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
