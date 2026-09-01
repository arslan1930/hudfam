<?php
$user = require_team();
$countryOptions = [];
try {
    ensure_prospect_schema();
    seed_countries_if_empty(db());
    $countryOptions = list_countries(null, true);
} catch (Throwable $e) {
    flash('error', 'Country list could not load. Open upgrade.php once if Filter stays empty, then retry.');
}
$raw = '';
$country = trim((string) (post('country') ?: get('country')));
$language = trim((string) (post('language') ?: get('language')));
$region = (string) (post('region') ?: get('region'));
$niche = '';
$notes = '';
$result = null;
$old = ['domains' => [], 'total' => 0, 'truncated' => false];
$canSendExtracting = team_page_unlocked($user, 'team_extract_batch');

// AJAX: group domains by public suffix (Separate all).
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) post('action') === 'group_tlds'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $parsed = parse_domain_list_strict((string) post('domains'));
    $groups = group_domains_by_tld($parsed['valid']);
    echo json_encode([
        'ok' => true,
        'groups' => $groups,
        'total' => count($parsed['valid']),
        'invalid' => (int) ($parsed['invalid_count'] ?? 0),
    ]);
    exit;
}

// Always use the existing country folder name (Germany, Spain, …) — never a free-text variant.
if ($country !== '') {
    $canonCountry = resolve_canonical_country($country);
    if ($canonCountry === null) {
        flash('error', 'Select an existing country database. New country folders are not created.');
        redirect('index.php?page=team_prospect_check');
    }
    $country = $canonCountry['name'];
    if ($region === '') {
        $region = $canonCountry['region'];
    }
    if ($language === '') {
        $language = $canonCountry['language'];
    }
    $language = function_exists('normalize_site_language')
        ? normalize_site_language($language, $country)
        : $language;
}

try {
    // Count only — never load/expose the existing country domain list to teammates.
    if ($country !== '') {
        $old = list_prospect_domain_names(1, $country);
        $old['domains'] = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) post('action');
        $raw = (string) post('domains');
        $country = trim((string) post('country'));
        $language = trim((string) post('language'));
        $region = (string) post('region');
        $niche = prospect_format_niches(prospect_parse_niches(trim((string) post('niche'))));
        $notes = trim((string) post('notes'));
        $parsed = parse_domain_list_strict($raw);
        $domains = $parsed['valid'];

        $canonCountry = $country !== '' ? resolve_canonical_country($country) : null;
        if ($country === '' || $canonCountry === null) {
            flash('error', 'Select an existing country database first (type to search, then Enter). New folders are not created.');
        } else {
            $country = $canonCountry['name'];
            if ($region === '') {
                $region = $canonCountry['region'];
            }
            if ($language === '') {
                $language = $canonCountry['language'];
            }
            $language = function_exists('normalize_site_language')
                ? normalize_site_language($language, $country)
                : $language;
        }

        if ($country === '' || $canonCountry === null) {
            // error already flashed
        } elseif ($parsed['invalid_count'] > 0 && $action !== 'add_new' && $action !== 'send_tld_column') {
            flash('error', 'Remove invalid lines first (Clean to root domains). Root domains only — e.g. example.com or my-site.co.uk.');
            $raw = $parsed['valid_text'] !== ''
                ? $parsed['valid_text'] . "\n" . implode("\n", array_column($parsed['invalid'], 'raw'))
                : $raw;
        } elseif ($action === 'add_new' || $action === 'send_tld_column') {
            // Must Filter unique sites first — Separate Send / Add cannot skip that step.
            if (!$domains) {
                flash('error', 'Could not read the domain list. Separate again or Filter, then retry.');
                redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
            }
            if (!prospect_filter_gate_allows($country, $domains)) {
                flash(
                    'error',
                    'Filter unique sites first. Separate and Add only work on domains that passed Filter for '
                    . $country . '.'
                );
                redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
            }
            // Route by TLD, then drop duplicates against each destination Our database.
            $filter = filter_domains_routed_against_prospects($domains, $country);
            $selected = $filter['new'];
            $already = count($filter['existing']);
            $result = [
                'existing' => $filter['existing'],
                'new' => $selected,
                'invalid' => 0,
                'total_input' => (int) $filter['total_input'],
                'by_country' => $filter['by_country'] ?? [],
            ];
            $raw = implode("\n", $selected);
            if (!$selected) {
                flash(
                    'ok',
                    'No new unique sites — all ' . (int) $already
                    . ' domain(s) are already in the destination country database(s). Paste a different list.'
                );
            } else {
                $destCount = 0;
                foreach (($filter['by_country'] ?? []) as $bucket) {
                    if (!empty($bucket['new'])) {
                        $destCount++;
                    }
                }
                // Multi-country routing handles .at/.ch/… — skip selected-country TLD warn.
                $tldGate = $destCount > 1
                    ? ['warn' => false]
                    : analyze_country_tld_match($selected, $country);
                $acked = post('confirm_tld_mismatch') === '1';
                if (!empty($tldGate['warn']) && !$acked) {
                    flash('error', 'Country/TLD mismatch warning: confirm the checkbox before adding to ' . $country . ', or change the country.');
                    $tldCheck = $tldGate;
                } else {
                    $added = add_prospect_domains($selected, $user, $country, $language, $region, $niche, $notes);
                    $dupN = (int) ($added['duplicated'] ?? $added['skipped'] ?? 0);
                    if ((int) $added['inserted'] < 1) {
                        if ($dupN > 0) {
                            flash('fade', prospect_duplicates_deleted_message($dupN) . '.');
                        } else {
                            flash('ok', 'No new unique sites were added — they are already in the destination country database(s).');
                        }
                        $result['new'] = [];
                        $raw = '';
                    } else {
                        $insertedN = (int) $added['inserted'];
                        $destNames = prospect_destination_names($added['by_country'] ?? [], 'inserted');
                        $msg = 'Extracting received ' . $insertedN
                            . ' site' . ($insertedN === 1 ? '' : 's');
                        if ($destNames !== '') {
                            $msg .= ' for ' . $destNames;
                        }
                        if (!empty($added['batch_id'])) {
                            $msg .= ' · saved in today’s history';
                        }
                        if (!empty($tldGate['warn'])) {
                            $msg .= ' · saved despite TLD mismatch warning';
                        }
                        if ($action === 'send_tld_column') {
                            $msg .= ' · sent from TLD column';
                        }
                        $msg .= '. Folder totals are shared — both teammates see the same Extracting country count after refresh.';
                        flash('ok', $msg);
                        if (!empty($added['extract_error'])) {
                            flash(
                                'error',
                                'Our database saved the sites, but Extracting did not: '
                                . (string) $added['extract_error']
                            );
                        }
                        if ($dupN > 0) {
                            flash('fade', prospect_duplicates_deleted_message($dupN) . '.');
                        }
                        // Send this ending: keep leftover unique so the next TLD can go out
                        // without re-pasting. Add unique (whole list) closes the gate.
                        if ($action === 'send_tld_column') {
                            prospect_filter_gate_subtract($country, $selected);
                        } else {
                            prospect_filter_gate_clear();
                        }
                        // Only jump to Extracting when that tool is unlocked for this user.
                        // Finding-only users stay on Filter (history is via Site adding history).
                        if (!empty($added['extract_batch_id']) && $canSendExtracting) {
                            redirect('index.php?page=team_extract_batch&id=' . (int) $added['extract_batch_id']);
                        }
                        redirect('index.php?page=team_prospect_check&country=' . urlencode($country));
                    }
                }
            }
        } elseif (count($domains) > 100000) {
            flash('error', 'Paste at most 100,000 domains per run (split into batches).');
        } elseif (!$domains) {
            flash('error', 'Paste at least one root domain under “Paste new sites”.');
        } else {
            // Filter: route by TLD, drop sites already in each destination country.
            $result = filter_domains_routed_against_prospects($domains, $country);
            $raw = implode("\n", $result['new']);
            $skippedN = count($result['existing']);
            $uniqueN = count($result['new']);
            prospect_filter_gate_set($country, $result['new']);
            $routeBits = [];
            foreach (($result['by_country'] ?? []) as $dest => $bucket) {
                $n = count($bucket['new'] ?? []);
                if ($n > 0) {
                    $routeBits[] = $n . ' → ' . $dest;
                }
            }
            if ($uniqueN > 0) {
                $msg = 'Filtered (TLD → country): removed ' . $skippedN
                    . ' already in destination database(s) · ' . $uniqueN
                    . ' unique site(s) ready to add (this Filter run)';
                if ($routeBits) {
                    $msg .= ' (' . implode(', ', $routeBits) . ')';
                }
                flash('ok', $msg . '.');
            } else {
                flash(
                    'ok',
                    'Filtered (TLD → country): all ' . $skippedN
                    . ' domain(s) are already in the destination country database(s). Nothing new to add.'
                );
            }
            // refresh private count after filter (domains stay hidden from teammates)
            $old = list_prospect_domain_names(1, $country);
            $old['domains'] = [];
        }
    }
} catch (InvalidArgumentException $e) {
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then try Filter again.');
}

// Drop a stale Filter gate when the selected country no longer matches.
$gateCountry = (string) (($_SESSION['prospect_filter_gate'] ?? [])['country'] ?? '');
if ($gateCountry !== '' && $country !== '' && $gateCountry !== $country) {
    prospect_filter_gate_clear();
}

// After Send this ending (GET back to Filter), re-show leftover unique from the gate.
if ($result === null && $country !== '') {
    $gated = prospect_filter_gate_domains($country);
    if ($gated) {
        try {
            $restored = filter_domains_routed_against_prospects($gated, $country);
            if (!empty($restored['new'])) {
                $result = $restored;
                $raw = implode("\n", $result['new']);
                prospect_filter_gate_set($country, $result['new']);
            } else {
                prospect_filter_gate_clear();
            }
        } catch (Throwable $e) {
            // Leave the paste box empty; user can Filter again.
        }
    }
}

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

$tldGroups = ($result && !empty($result['new']))
    ? group_domains_by_tld($result['new'])
    : [];
$sendBtnLabel = 'Send this ending';

$stepPaste = !$result ? 'active' : 'done';
$stepFilter = $result ? 'active' : '';
$stepAdd = ($result && $result['new']) ? 'active' : '';

render_header('Filter & add', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Your work', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Filter & add'],
]); ?>
<div class="topbar">
  <div>
    <h1>Filter &amp; add<?= $country !== '' ? ' · ' . h($country) : '' ?></h1>
    <p class="muted">Paste sites → <strong>Filter unique sites</strong> routes by TLD (.at→Austria, .ch→Switzerland, …) and removes duplicates in each destination Our database → Add puts unique sites into those country folders and Extracting Sites lists. Separate before Filter can only Copy/Delete.</p>
  </div>
  <div class="actions">
    <?php if ($country !== ''): ?>
      <?php render_task_presence('prospect:' . $country, 'Others adding sites for ' . $country); ?>
    <?php endif; ?>
    <?php if (team_page_unlocked($user, 'team_semrush_research')): ?>
      <a class="btn secondary" href="index.php?page=team_semrush_research">Semrush Research</a>
    <?php endif; ?>
    <?php if (team_page_unlocked($user, 'team_extracting')): ?>
      <a class="btn secondary" href="index.php?page=team_extracting">Extracting sites</a>
    <?php endif; ?>
    <?php if (team_page_unlocked($user, 'team_prospect_batches')): ?>
      <a class="btn secondary" href="index.php?page=team_prospect_batches">Site adding history</a>
    <?php endif; ?>
  </div>
</div>
<?= guide_filter_add() ?>

<ul class="steps">
  <li class="step <?= $stepPaste ?>"><span class="num">1</span> Country + paste</li>
  <li class="step <?= $stepFilter ?>"><span class="num">2</span> Filter (remove known)</li>
  <li class="step <?= $stepAdd ?>"><span class="num">3</span> Add new only</li>
</ul>

<form method="post" id="filter_form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="filter">

  <div class="card" style="margin-bottom:1rem">
    <div class="form-grid">
      <?= render_country_typeahead($country, [
          'id' => 'country',
          'label' => 'Country database',
          'attrs' => 'data-fill-language="#language" data-fill-region="select[name=region]" data-reload-on-select="1"',
      ]) ?>
      <div class="full">
        <p class="help" style="margin:0">
          Starting folder for generic TLDs (.com, .net, .eu). Country endings (.at, .ch, …) route to their own folders.
          Existing Our database URLs stay hidden. Filter leftover unique is this session; Extracting country totals are shared.
        </p>
      </div>
      <input type="hidden" name="language" id="language" value="<?= h($language) ?>">
      <div><label>Region</label>
        <select name="region">
          <option value="">—</option>
          <?php foreach (regions() as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $region === $k ? 'selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label for="niche_q">Niche</label>
        <?= render_niche_chip_box($niche, [
            'name' => 'niche',
            'id' => 'niche',
            'placeholder' => 'Type a niche, Enter to add',
        ]) ?>
        <p class="help">English niches. Type to add, × to remove. A site can have more than one.</p>
      </div>
      <div class="full"><label>Notes</label><textarea name="notes" rows="2"><?= h($notes) ?></textarea></div>
    </div>
  </div>

  <div class="card box-panel">
    <h2>Paste new sites</h2>
    <?= render_domains_paste_field('domains', $raw, [
        'id' => 'domains',
        'label' => 'Root domains',
        'required' => true,
        'rows' => 14,
        'class' => 'inventory-box',
    ]) ?>
  </div>

  <div class="actions-sticky">
    <button class="btn large block" type="submit" style="max-width:420px;margin:0 auto;display:block"
            id="filter_submit"
            <?= $country === '' ? 'disabled' : '' ?>
            title="<?= $country === ''
                ? 'Select a country first'
                : 'Remove sites already in this country and show unique only' ?>">
      Filter unique sites
    </button>
  </div>
</form>

<?php
  // Paste Separate: Copy/Delete only. Send/Add requires Filter unique sites first
  // (results Separate below has data-can-send=1 after Filter).
  $pasteCanSend = false;
?>
<div class="card tld-separate-card"
     data-tld-separate
     data-source="#domains"
     data-group-url="index.php?page=team_prospect_check"
     data-csrf="<?= h(csrf_token()) ?>"
     data-country="<?= h($country) ?>"
     data-language="<?= h($language) ?>"
     data-region="<?= h($region) ?>"
     data-niche="<?= h($niche) ?>"
     data-notes="<?= h($notes) ?>"
     data-can-send="<?= $pasteCanSend ? '1' : '0' ?>"
     data-send-label="<?= h($sendBtnLabel) ?>">
  <div class="tld-separate-toolbar">
    <button type="button" class="btn secondary" data-tld-separate-btn
            title="Split the paste box by domain ending (.es, .com, .pe, …)">
      Separate all
    </button>
    <span class="muted" style="font-size:0.88rem">
      One ending at a time — Copy or Delete only. <?= h($sendBtnLabel) ?> appears after
      <strong>Filter unique sites</strong>
    </span>
    <p class="help tld-separate-status" data-tld-status hidden></p>
  </div>
  <div class="tld-separate-workspace" data-tld-workspace hidden>
    <div class="tld-separate-rail" data-tld-rail hidden></div>
    <div class="tld-separate-panel" data-tld-panel hidden></div>
  </div>
</div>

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
<?= prospect_niche_taxonomy_script() ?>
<?= niche_chips_script_tag() ?>

<?php if ($result): ?>
<div class="card">
  <h2>Results · <?= h($country) ?></h2>
  <p class="muted" style="margin:0">
    Pasted <strong><?= (int) $result['total_input'] ?></strong> ·
    Already in destination country database(s) <strong><?= count($result['existing']) ?></strong> ·
    Unique this Filter <strong><?= count($result['new']) ?></strong>
    <?php
      $routeSummary = [];
      foreach (($result['by_country'] ?? []) as $dest => $bucket) {
          $n = count($bucket['new'] ?? []);
          if ($n > 0) {
              $routeSummary[] = $n . ' → ' . $dest;
          }
      }
      if ($routeSummary):
    ?>
      · <?= h(implode(', ', $routeSummary)) ?>
    <?php endif; ?>
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
      echo h(implode(', ', array_map(static fn ($t) => '.' . $t, $exp)));
    ?>.
    Match on country-specific TLDs: <strong><?= (int) $tldCheck['match_pct'] ?>%</strong>
    (<?= (int) $tldCheck['matched'] ?>/<?= (int) $tldCheck['signal'] ?>). Warns below 70%.
  </p>
</div>
<?php endif; ?>

<div class="grid two-box">
  <div class="card panel-muted">
    <h2>Already known (skipped)</h2>
    <?php if ($result['existing']): ?>
      <?php $existPhrase = prospect_destinations_phrase($result['by_country'] ?? [], 'existing'); ?>
      <div class="empty-state" style="min-height:10rem;display:flex;align-items:center;justify-content:center;text-align:center;padding:1.25rem">
        <p class="muted" style="margin:0;max-width:20rem">
          <strong><?= count($result['existing']) ?></strong> site<?= count($result['existing']) === 1 ? '' : 's' ?>
          from your paste already exist in destination country database(s)<?php
            if ($existPhrase !== '') {
                echo ' (' . h($existPhrase) . ')';
            }
          ?> and were skipped.<br>
          Existing country URLs stay hidden for privacy.
        </p>
      </div>
    <?php else: ?>
      <div class="empty-state"><p>Nothing skipped — all pasted domains are new for their destination countries.</p></div>
    <?php endif; ?>
  </div>
  <div class="card panel-ok">
    <h2>New unique sites only</h2>
    <?php if ($result['new']): ?>
      <form method="post" id="add_unique_form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_new">
        <input type="hidden" name="country" value="<?= h($country) ?>">
        <input type="hidden" name="language" value="<?= h($language) ?>">
        <input type="hidden" name="region" value="<?= h($region) ?>">
        <input type="hidden" name="niche" value="<?= h($niche) ?>">
        <?= render_hidden_multiline('notes', $notes) ?>
        <?php
          $uniqueText = implode("\n", $result['new']);
          $uniquePreview = implode("\n", array_slice($result['new'], 0, 5000));
          if (count($result['new']) > 5000) {
              $uniquePreview .= "\n… +" . (count($result['new']) - 5000) . ' more';
          }
        ?>
        <?= render_hidden_multiline('domains', $uniqueText) ?>
        <textarea id="unique_domains_preview" class="inventory-box" rows="10" readonly><?= h($uniquePreview) ?></textarea>
        <p class="help">
          These are <strong>not</strong> in their destination country Our database yet
          (TLD routing: .at→Austria, .ch→Switzerland, .com stays in <?= h($country) ?>, …).
          This leftover is <strong>this Filter run</strong> (your session) — not the shared country total.
          Add merges them into the correct country folders and Extracting Sites lists
          (shared — both teammates see the same folder count after refresh).
          Or <strong>Separate all</strong> below to Send/Add one domain ending at a time.
        </p>
        <?php if (!empty($tldCheck['warn'])): ?>
          <label class="tld-confirm">
            <input type="checkbox" name="confirm_tld_mismatch" value="1" required>
            I confirm these sites belong in <strong><?= h($country) ?></strong> (or I accept saving them there anyway).
          </label>
        <?php endif; ?>
        <?php
          $addN = count($result['new']);
          $addDest = prospect_destinations_phrase($result['by_country'] ?? [], 'new');
          $addLabel = 'Add ' . $addN . ' unique site' . ($addN === 1 ? '' : 's');
          if ($addDest !== '') {
              $addLabel .= ' (' . $addDest . ')';
          }
        ?>
        <div class="actions-sticky">
          <button class="btn large block" type="submit" id="add_unique_btn">
            <?= h($addLabel) ?>
          </button>
        </div>
      </form>

      <div class="tld-separate-card"
           data-tld-separate
           data-source="#unique_domains_preview"
           data-group-url="index.php?page=team_prospect_check"
           data-csrf="<?= h(csrf_token()) ?>"
           data-country="<?= h($country) ?>"
           data-language="<?= h($language) ?>"
           data-region="<?= h($region) ?>"
           data-niche="<?= h($niche) ?>"
           data-notes="<?= h($notes) ?>"
           data-can-send="1"
           data-send-label="<?= h($sendBtnLabel) ?>"
           data-groups-json="<?= h(json_encode($tldGroups, JSON_UNESCAPED_UNICODE)) ?>">
        <div class="tld-separate-toolbar">
          <button type="button" class="btn" data-tld-separate-btn
                  title="Split unique sites by domain ending">
            Separate all
          </button>
          <span class="muted" style="font-size:0.88rem">
            One ending at a time — Copy, Delete, or <?= h($sendBtnLabel) ?> (passed Filter unique sites)
          </span>
          <p class="help tld-separate-status" data-tld-status hidden></p>
        </div>
        <div class="tld-separate-workspace" data-tld-workspace hidden>
          <div class="tld-separate-rail" data-tld-rail hidden></div>
          <div class="tld-separate-panel" data-tld-panel hidden></div>
        </div>
      </div>

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
        <p>No new sites to add — everything you pasted is already in the destination country database(s).</p>
        <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Paste a new list</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<script src="<?= h(script_asset_url('js/tld-separate.js')) ?>" defer></script>
<?php render_footer('team'); ?>
