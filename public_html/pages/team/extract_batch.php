<?php
$user = require_team();
ensure_extract_schema();

$id = (int) (get('id') ?: 0);
$batch = $id > 0 ? get_extract_batch($id) : null;
if (!$batch) {
    flash('error', 'That country batch is not available yet. Waiting for sites from the team mate.');
    redirect('index.php?page=team_extracting');
}

$resultsText = (string) ($batch['results_text'] ?? '');
$country = (string) $batch['country'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = extract_request_wants_json();

    if ($action === 'autosave_sites') {
        $raw = (string) post('sites_text');
        $actorId = (int) ($user['id'] ?? 0) ?: null;
        $expectedCount = post('expected_count') !== '' && post('expected_count') !== null
            ? (int) post('expected_count')
            : null;
        $conflict = extract_sites_writer_conflict(
            $id,
            $actorId,
            (string) post('writer_at'),
            $expectedCount
        );
        if (is_array($conflict) && !empty($conflict['conflict'])) {
            $fresh = get_extract_batch($id);
            $conflict['domains'] = get_extract_batch_domains($id);
            $conflict['site_count'] = (int) ($fresh['site_count'] ?? count($conflict['domains']));
            $conflict['writer_at'] = (string) ($fresh['sites_writer_at'] ?? ($conflict['writer_at'] ?? ''));
            $conflict['writer_name'] = trim((string) (($fresh['sites_writer_name'] ?? '') !== ''
                ? $fresh['sites_writer_name']
                : ($fresh['sites_writer_username'] ?? ($conflict['writer_name'] ?? ''))));
            if ($wantsJson) {
                extract_json_response($conflict, 409);
            }
            flash('error', (string) ($conflict['error'] ?? 'Reload to avoid overwriting.'));
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        if (is_array($conflict)) {
            if ($wantsJson) {
                extract_json_response($conflict, 404);
            }
            flash('error', (string) ($conflict['error'] ?? 'Batch not found.'));
            redirect('index.php?page=team_extracting');
        }
        try {
            $synced = set_extract_batch_domains_from_text(
                $id,
                $raw,
                $actorId
            );
        } catch (Throwable $e) {
            if ($wantsJson) {
                extract_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            flash('error', $e->getMessage());
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        $siteCount = (int) $synced['site_count'];
        if ($wantsJson) {
            extract_json_response([
                'ok' => true,
                'site_count' => $siteCount,
                'removed' => (int) $synced['removed'],
                'added' => (int) $synced['added'],
                'domains' => $synced['domains'],
                'empty' => $siteCount < 1,
                'writer_name' => (string) ($synced['writer_name'] ?? ''),
                'writer_at' => (string) ($synced['writer_at'] ?? ''),
                'message' => $siteCount < 1
                    ? 'Sites list empty — this country stays open here; it hides on Extracting sites and is removed after 1 hour unless new sites are added.'
                    : null,
            ]);
        }
        flash('ok', 'Sites list updated (' . $siteCount . ').');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }

    if ($action === 'push_results') {
        $resultsText = (string) post('results_text');
        // Keep draft text on the batch while validating / if push fails partially.
        save_extract_batch_results($id, $resultsText);
        try {
            $pushed = push_extract_results_to_extracted(
                $resultsText,
                $country,
                $user,
                (string) ($batch['language'] ?? ''),
                (string) ($batch['region'] ?? ''),
                $id
            );
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        if ($pushed['inserted'] < 1 && $pushed['skipped'] < 1) {
            flash(
                'error',
                $pushed['invalid'] > 0
                    ? 'Could not push — fix invalid lines first (root domains only).'
                    : 'Paste at least one site into Extracting Results before Push.'
            );
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        // Only clear Results when something new was inserted; keep paste if only duplicates.
        if ((int) $pushed['inserted'] > 0) {
            save_extract_batch_results($id, '');
            // Remove successfully pushed domains from this country's Sites list.
            $pushedDomains = [];
            $rawLines = preg_split('/\R+/', $resultsText) ?: [];
            foreach ($rawLines as $line) {
                $d = normalize_domain(trim((string) $line));
                if ($d !== '') {
                    $pushedDomains[] = $d;
                }
            }
            if ($pushedDomains !== []) {
                remove_extract_batch_domains($id, $pushedDomains);
                refresh_extract_batch_site_count($id);
            }
        }
        $byCountry = is_array($pushed['by_country'] ?? null) ? $pushed['by_country'] : [];
        $countryBits = [];
        $semrushInserted = 0;
        foreach ($byCountry as $cName => $stats) {
            $n = (int) ($stats['inserted'] ?? 0) + (int) ($stats['skipped'] ?? 0);
            if ($n > 0) {
                $countryBits[] = $cName . ': ' . $n;
            }
            $semrushInserted += (int) ($stats['semrush_inserted'] ?? 0);
        }
        if (count($countryBits) > 1) {
            $msg = 'Pushed ' . (int) $pushed['inserted'] . ' site(s) across '
                . count($countryBits) . ' countries (' . implode(', ', $countryBits) . ')';
            $msg .= '. Country TLDs were routed to their folders; .com/.net/.eu/etc. stayed in '
                . (string) $pushed['country'];
        } else {
            $msg = 'Pushed ' . (int) $pushed['inserted'] . ' site(s) to Extracted Sites and Sites with emails - Team · '
                . (string) $pushed['country'];
        }
        if ($semrushInserted > 0) {
            $msg .= ' · ' . $semrushInserted . ' also copied to Semrush Research';
        }
        if ((int) $pushed['skipped'] > 0) {
            $msg .= ' · ' . (int) $pushed['skipped'] . ' already there';
        }
        if ((int) $pushed['invalid'] > 0) {
            $msg .= ' · ' . (int) $pushed['invalid'] . ' invalid line(s) skipped';
        }
        if ((int) $pushed['inserted'] < 1 && (int) $pushed['skipped'] > 0) {
            $msg .= ' · Results kept so you can edit and retry';
        }
        flash('ok', $msg . '.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }
}

$siteRows = get_extract_batch_site_rows($id);
$domains = array_column($siteRows, 'domain');
if (count($domains) < 1) {
    refresh_extract_batch_site_count($id);
}

render_header('Extracting · ' . $country, 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Extracting sites', 'href' => 'index.php?page=team_extracting'],
    ['label' => $country],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($country) ?> · Extracting</h1>
    <p class="muted">
      <span id="sites_count_label"><?= count($domains) ?></span> site<?= count($domains) === 1 ? '' : 's' ?> in Sites list
    </p>
  </div>
  <div class="actions">
    <?php render_task_presence('extract:' . $country, 'Others extracting ' . $country); ?>
    <a class="btn secondary" href="index.php?page=team_extracting">All countries</a>
    <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
      <a class="btn secondary" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Add more sites</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid two-box">
  <div class="card box-panel">
    <h2>① Sites list</h2>
    <p class="help">
      Sites waiting to extract for <strong><?= h($country) ?></strong>.
      Changes <strong>autosave</strong> in real time.
      <strong>Undo</strong>/<strong>Redo</strong> work while you stay on this page.
      If another teammate (or Filter &amp; add) updates this list while you have it open, your tab reloads the full list instead of overwriting.
      If emptied, this page stays open; the country hides when you return to Extracting sites,
      and the row is removed after <strong>1 hour</strong> unless new sites are added (new sites appear at the top).
    </p>

    <?php
      $serverSitesText = implode("\n", $domains);
    ?>
    <div
      class="domains-paste"
      id="sites_list_shell"
      data-batch-id="<?= (int) $id ?>"
      data-post-url="index.php?page=team_extract_batch&amp;id=<?= (int) $id ?>"
      data-writer-at="<?= h((string) ($batch['sites_writer_at'] ?? '')) ?>"
      data-site-count="<?= (int) count($domains) ?>"
    >
      <div class="domains-paste-head">
        <label for="sites_list_text">Sites (root domains)</label>
          <div class="sites-list-actions">
            <?php render_undo_redo_arrow_buttons('sites_undo_btn', 'sites_redo_btn'); ?>
            <button type="button" class="btn secondary small" id="sites_copy_all">Copy all</button>
          </div>
      </div>
      <textarea
        id="sites_list_text"
        class="inventory-box"
        rows="16"
        spellcheck="false"
        aria-label="Sites list"
        placeholder="Waiting for sites from the team mate"
      ><?= h($serverSitesText) ?></textarea>
      <p class="help" style="margin-top:0.5rem">
        Root domain only — e.g. <code>example.com</code> or <code>my-site.co.uk</code>.
        Hyphens and multi-part TLDs are OK.
        One per line (or commas). Autosave normalizes <code>https</code>/paths to root domains;
        invalid lines are removed so this box matches the saved list.
      </p>
      <p class="muted" style="margin:0.35rem 0 0">
        <span id="sites_footer_count"><?= count($domains) ?> site<?= count($domains) === 1 ? '' : 's' ?></span>
        <span id="sites_autosave_label" class="help" style="margin-left:0.5rem"><?php
            $wName = trim((string) (($batch['sites_writer_name'] ?? '') !== ''
                ? $batch['sites_writer_name']
                : ($batch['sites_writer_username'] ?? '')));
            echo h(last_writer_label($wName, (string) ($batch['sites_writer_at'] ?? '')));
        ?></span>
      </p>
      <p class="help sites-list-status" id="sites_list_status" hidden></p>
    </div>
  </div>

  <div class="card box-panel">
    <h2>② Extracting Results</h2>
    <p class="help">
      Paste extracted sites, <strong>Clean to root domains</strong> if needed, then <strong>Push</strong>.
      <strong>Push uses the Ready list only</strong> (Needs attention stays aside).
      Country TLDs auto-route (<strong>.de</strong>→Germany, <strong>.at</strong>→Austria, <strong>.ch</strong>→Switzerland, …).
      Generic TLDs (<strong>.com</strong>, <strong>.net</strong>, <strong>.eu</strong>, …) stay in <strong><?= h($country) ?></strong>.
      Sites go to Extracted Sites + Sites with emails - Team in each destination country.
    </p>
    <form method="post" id="extract_results_form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="push_results">
      <?= render_domains_paste_field('results_text', $resultsText, [
          'id' => 'results_text',
          'label' => 'Results (Ready root domains)',
          'rows' => 16,
          'class' => 'inventory-box',
          'placeholder' => "Paste sites…\nexample.com\nshop.de\nblog.fr",
      ]) ?>
      <div class="actions-sticky" style="margin-top:0.75rem">
        <button class="btn large" type="submit" id="extract_push_btn"
                title="Push Ready domains to Extracted Sites and Sites with emails - Team">Push</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= h(script_asset_url('js/extract-sites-list.js')) ?>" defer></script>
<?= sites_form_script_tag() ?>
<?php render_footer('team'); ?>
