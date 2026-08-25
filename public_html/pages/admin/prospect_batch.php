<?php
$user = require_admin();
// Do not clear Our database "New" from history — only Our database pages should.
$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Site adding history day not found.');
    redirect('index.php?page=admin_prospect_batches');
}

$postUrl = 'index.php?page=admin_prospect_batch&id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $jsonOut = static function (array $payload, int $code = 200) use ($wantsJson): void {
        if (!$wantsJson) {
            return;
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    };

    try {
        if ($action === 'autosave_sites') {
            $result = set_prospect_batch_domains_from_text(
                $id,
                (string) post('sites_text'),
                (string) post('also_remove_db') === '1'
            );
            if (empty($result['ok'])) {
                $jsonOut(['ok' => false, 'error' => (string) ($result['error'] ?? 'Autosave failed')], 400);
                flash('error', (string) ($result['error'] ?? 'Autosave failed'));
                redirect($postUrl);
            }
            $jsonOut([
                'ok' => true,
                'total' => (int) ($result['total'] ?? 0),
                'removed' => (int) ($result['removed'] ?? 0),
                'inserted' => (int) ($result['inserted'] ?? 0),
                'db_removed' => (int) ($result['db_removed'] ?? 0),
            ]);
            flash('ok', 'Sites saved.');
            redirect($postUrl);
        }

        if ($action === 'save_meta') {
            $result = update_prospect_batch_meta(
                $id,
                (string) post('country'),
                (string) post('language'),
                (string) post('region'),
                (string) post('niche'),
                (string) post('notes')
            );
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not save details.'));
            } else {
                flash('ok', 'Day details saved.');
            }
            redirect($postUrl);
        }

        if ($action === 'delete_batch') {
            $alsoDb = (string) post('also_remove_db') === '1';
            $result = delete_prospect_batch($id, $alsoDb);
            if (empty($result['ok'])) {
                flash('error', (string) ($result['error'] ?? 'Could not delete.'));
                redirect($postUrl);
            }
            $msg = 'Deleted this history day.';
            if ($alsoDb && (int) ($result['db_removed'] ?? 0) > 0) {
                $msg .= ' Removed ' . (int) $result['db_removed'] . ' from Our database.';
            }
            flash('ok', $msg);
            redirect('index.php?page=admin_prospect_batches&user=' . (int) $batch['user_id']);
        }
    } catch (Throwable $e) {
        $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
        flash('error', $e->getMessage());
        redirect($postUrl);
    }

    flash('error', 'Unknown action.');
    redirect($postUrl);
}

$batch = get_prospect_batch($id) ?: $batch;
$items = get_prospect_batch_items($id);
$domains = array_column($items, 'domain');
$person = $batch['full_name'] ?: $batch['username'];
$sitesText = implode("\n", $domains);
$siteCount = count($domains);

render_header('Site adding history · ' . $batch['batch_date'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Site adding history', 'href' => 'index.php?page=admin_prospect_batches'],
    ['label' => $batch['batch_date'] . ' · ' . $person],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($person) ?></h1>
    <p class="muted">
      <span id="history_count_label"><?= (int) $siteCount ?></span> site(s)
      · <?= h($batch['country'] ?: '—') ?>
      · role <?= h($batch['role'] ?? '—') ?>
      · <span id="history_autosave_label" class="help">Saved</span>
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $batch['user_id'] ?>">This teammate</a>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">All history</a>
    <?php
    $inventoryHref = 'index.php?page=admin_prospects&created_by=' . (int) $batch['user_id'];
    $batchCountry = trim((string) ($batch['country'] ?? ''));
    if ($batchCountry !== '' && resolve_canonical_country($batchCountry) !== null) {
        $inventoryHref .= '&country=' . rawurlencode(resolve_canonical_country($batchCountry)['name']);
    }
    ?>
    <a class="btn secondary" href="<?= h($inventoryHref) ?>">Inventory by person</a>
  </div>
</div>

<div class="card">
  <h2>Day details</h2>
  <p class="help">This only updates this history day. It does not move sites between Our database folders.</p>
  <form method="post" action="<?= h($postUrl) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_meta">
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:0.75rem">
      <div>
        <?= render_country_typeahead((string) ($batch['country'] ?? ''), [
            'id' => 'history_country',
            'label' => 'Country',
            'required' => false,
            'optional' => true,
            'help' => 'Leave blank to keep the current country. Saving does not re-file sites in Our database.',
        ]) ?>
      </div>
      <div>
        <label>Language</label>
        <input name="language" value="<?= h((string) ($batch['language'] ?? '')) ?>">
      </div>
      <div>
        <label>Region</label>
        <input name="region" value="<?= h((string) ($batch['region'] ?? '')) ?>">
      </div>
      <div>
        <label>Niche</label>
        <input name="niche" value="<?= h((string) ($batch['niche'] ?? '')) ?>">
      </div>
    </div>
    <label>Notes</label>
    <textarea name="notes" rows="2"><?= h((string) ($batch['notes'] ?? '')) ?></textarea>
    <p class="actions" style="margin-top:0.75rem">
      <button class="btn secondary" type="submit">Save details</button>
    </p>
  </form>
</div>
<?= sites_form_script_tag() ?>

<div class="card" id="history_sites_shell" data-post-url="<?= h($postUrl) ?>">
  <div class="topbar" style="margin-bottom:0.75rem">
    <div>
      <h2 style="margin:0">Sites</h2>
      <p class="muted" style="margin:0.25rem 0 0">
        Edit the list — changes autosave.
        <strong>New lines are added to Our database</strong> for this day’s country.
        Removing a line drops it from this history day only.
        To also delete those domains from Our database, use <strong>Delete this day</strong> below.
      </p>
    </div>
    <div class="actions">
      <?php render_undo_redo_arrow_buttons('history_undo_btn', 'history_redo_btn', ''); ?>
      <button type="button" class="btn secondary" id="history_copy_all" <?= $siteCount ? '' : 'disabled' ?>>Copy all</button>
    </div>
  </div>
  <p id="history_list_status" class="help" hidden></p>
  <textarea id="history_sites_text" class="inventory-box" rows="16" spellcheck="false"><?= h($sitesText) ?></textarea>
  <div class="actions" style="margin-top:0.75rem;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:0.75rem">
    <span class="muted" id="history_footer_count"><?= (int) $siteCount ?> site<?= $siteCount === 1 ? '' : 's' ?></span>
  </div>
  <?php if ($items): ?>
  <details style="margin-top:1rem">
    <summary>Added timestamps</summary>
    <table style="margin-top:0.75rem">
      <thead><tr><th>Domain</th><th>Added at</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><?= h($item['domain']) ?></td>
          <td><?= h((string) $item['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Delete this day</h2>
  <p class="muted">Removes the history day. Our database stays unchanged unless you check the box.</p>
  <form method="post" action="<?= h($postUrl) ?>" onsubmit="return confirm('Delete this history day? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_batch">
    <label style="font-weight:500">
      <input type="checkbox" name="also_remove_db" value="1">
      Also remove this day’s domains from Our database
    </label>
    <p class="actions" style="margin-top:0.75rem">
      <button class="btn secondary" type="submit" style="color:#b42318">Delete history day</button>
    </p>
  </form>
</div>

<script src="<?= h(script_asset_url('js/prospect-batch-sheet.js')) ?>" defer></script>
<?php render_footer('admin'); ?>
