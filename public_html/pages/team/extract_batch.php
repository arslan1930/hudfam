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
                    ? 'Could not push — fix invalid lines first (root domains only, or use Clean-style https URLs).'
                    : 'Paste at least one site into Extracting Results before Push.'
            );
            redirect('index.php?page=team_extract_batch&id=' . $id);
        }
        // Clear the box after a successful push into admin Extracted URLs.
        save_extract_batch_results($id, '');
        $msg = 'Pushed ' . (int) $pushed['inserted'] . ' site(s) to Extracted URLs → Extracted Sites · ' . $pushed['country'];
        if ((int) $pushed['skipped'] > 0) {
            $msg .= ' · ' . (int) $pushed['skipped'] . ' already there';
        }
        if ((int) $pushed['invalid'] > 0) {
            $msg .= ' · ' . (int) $pushed['invalid'] . ' invalid line(s) skipped';
        }
        flash('ok', $msg . '.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }

    if ($action === 'remove_sites') {
        $selected = post('domains');
        if (!is_array($selected)) {
            $raw = (string) post('domains_json');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $selected = is_array($decoded) ? $decoded : [];
        }
        $removed = remove_extract_batch_domains($id, $selected);
        $siteCount = refresh_extract_batch_site_count($id);
        if ($wantsJson) {
            extract_json_response([
                'ok' => true,
                'removed' => $removed,
                'site_count' => $siteCount,
            ]);
        }
        flash('ok', 'Removed ' . count($removed) . ' site(s) from the Sites list.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }

    if ($action === 'restore_sites') {
        $raw = (string) post('rows_json');
        $rows = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($rows)) {
            $rows = [];
        }
        $restored = restore_extract_batch_domains($id, $rows);
        $siteCount = refresh_extract_batch_site_count($id);
        $domains = get_extract_batch_domains($id);
        if ($wantsJson) {
            extract_json_response([
                'ok' => true,
                'restored' => $restored,
                'site_count' => $siteCount,
                'domains' => $domains,
            ]);
        }
        flash('ok', 'Restored ' . $restored . ' site(s) to the Sites list.');
        redirect('index.php?page=team_extract_batch&id=' . $id);
    }
}

$siteRows = get_extract_batch_site_rows($id);
$domains = array_column($siteRows, 'domain');

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
      · <?= h((string) ($batch['language'] ?: '—')) ?>
      · <?= h((string) ($batch['region'] ?: '—')) ?>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_extracting">All countries</a>
    <a class="btn" href="index.php?page=team_prospect_check&amp;country=<?= urlencode($country) ?>">Add more sites</a>
  </div>
</div>

<div class="grid two-box">
  <div class="card box-panel">
    <h2>① Sites list</h2>
    <p class="help">
      Sites waiting to extract for <strong><?= h($country) ?></strong>.
      Edit freely — <kbd>Backspace</kbd> removes text, <strong>Undo</strong>/<strong>Redo</strong> restore it.
      Your working list and selection are kept after refresh (temporary history on this browser).
    </p>

    <?php
      $serverSitesText = implode("\n", $domains);
    ?>
    <div
      class="domains-paste"
      id="sites_list_shell"
      data-batch-id="<?= (int) $id ?>"
    >
      <script type="application/json" id="sites_list_server_json"><?= json_encode(
          $serverSitesText,
          JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?></script>
      <div class="domains-paste-head">
        <label for="sites_list_text">Sites (root domains)</label>
        <div class="sites-list-actions">
          <button type="button" class="btn secondary small" id="sites_undo_btn" disabled>Undo</button>
          <button type="button" class="btn secondary small" id="sites_redo_btn" disabled>Redo</button>
          <button type="button" class="btn secondary small" id="sites_copy_all" <?= $domains ? '' : 'disabled' ?>>Copy all</button>
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
        One per line (or commas). Use <strong>Clean errors</strong> to correct
        <code>https</code>, paths, and subdomains into root domains (unfixable lines are kept).
      </p>
      <p class="muted" style="margin:0.35rem 0 0" id="sites_footer_count">
        <?= count($domains) ?> site<?= count($domains) === 1 ? '' : 's' ?>
      </p>
      <p class="help sites-list-status" id="sites_list_status" hidden></p>
    </div>
  </div>

  <div class="card box-panel">
    <h2>② Extracting Results</h2>
    <p class="help">
      Paste extracted sites for <strong><?= h($country) ?></strong>, then <strong>Push</strong>
      to send them into Admin → Extracted URLs → Extracted Sites → <?= h($country) ?>.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="push_results">
      <textarea class="inventory-box" name="results_text" rows="16" placeholder="Paste sites for <?= h($country) ?>…&#10;example.com&#10;another-site.de"><?= h($resultsText) ?></textarea>
      <div class="actions-sticky" style="margin-top:0.75rem">
        <button class="btn large" type="submit">Push</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= h(script_asset_url('js/extract-sites-list.js')) ?>" defer></script>
<?php render_footer('team'); ?>
