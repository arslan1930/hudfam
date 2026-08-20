<?php
/**
 * Admin · one Site adding history day — edit domains (copy/cut/undo), delete day.
 */
$user = require_admin();
if (function_exists('clear_admin_new_data')) {
    clear_admin_new_data('our_database', $user);
}

$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Site adding history day not found.');
    redirect('index.php?page=admin_prospect_batches');
}

$base = 'index.php?page=admin_prospect_batch&id=' . $id;
$listUrl = 'index.php?page=admin_prospect_batches';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $json = static function (array $payload, int $code = 200) use ($wantsJson, $base): void {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);
            exit;
        }
        if (!empty($payload['ok'])) {
            flash('ok', (string) ($payload['message'] ?? 'Saved.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not complete.'));
        }
        redirect($base);
    };

    if ($action === 'autosave_sites' || $action === 'save_sites') {
        $alsoDb = (string) post('also_remove_db') === '1';
        $result = set_prospect_batch_domains_from_text($id, (string) post('sites_text'), $alsoDb);
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not save.')], 400);
        }
        $total = (int) ($result['total'] ?? 0);
        $json([
            'ok' => true,
            'total' => $total,
            'inserted' => (int) ($result['inserted'] ?? 0),
            'removed' => (int) ($result['removed'] ?? 0),
            'db_removed' => (int) ($result['db_removed'] ?? 0),
            'domains' => $result['domains'] ?? [],
            'message' => 'Saved ' . $total . ' site' . ($total === 1 ? '' : 's') . ' in history.',
        ]);
    }

    if ($action === 'save_meta') {
        $result = update_prospect_batch_meta(
            $id,
            (string) post('notes'),
            (string) post('country')
        );
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not update.'));
            redirect($base);
        }
        flash('ok', 'History day details updated.');
        redirect($base);
    }

    if ($action === 'delete_day') {
        $alsoDb = (string) post('also_remove_db') === '1';
        $result = delete_prospect_batch($id, $alsoDb);
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not delete.'));
            redirect($base);
        }
        $msg = 'Deleted Site adding history for ' . (string) ($result['batch_date'] ?? '')
            . ' (' . (int) ($result['cleared'] ?? 0) . ' site(s)).';
        if ((int) ($result['db_removed'] ?? 0) > 0) {
            $msg .= ' Also removed ' . (int) $result['db_removed'] . ' from Our database.';
        }
        flash('ok', $msg);
        redirect($listUrl . '&user=' . (int) $batch['user_id']);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$batch = get_prospect_batch($id);
$items = get_prospect_batch_items($id);
$domains = array_column($items, 'domain');
$sitesText = implode("\n", $domains);
$total = count($domains);
$person = $batch['full_name'] ?: $batch['username'];

render_header('Site adding history · ' . $batch['batch_date'], 'admin');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Site adding history', 'href' => $listUrl],
    ['label' => $batch['batch_date'] . ' · ' . $person],
]);
?>
<div class="topbar">
  <div>
    <h1><?= h($batch['batch_date']) ?> · <?= h($person) ?></h1>
    <p class="muted">
      <span id="history_count_label"><?= (int) $total ?></span> site(s) in this day’s history
      · <?= h($batch['country'] ?: '—') ?>
      · role <?= h($batch['role'] ?? '—') ?>
      · edit, copy, cut, undo/redo
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="<?= h($listUrl) ?>&amp;user=<?= (int) $batch['user_id'] ?>">This teammate</a>
    <a class="btn secondary" href="<?= h($listUrl) ?>">All history</a>
    <a class="btn secondary" href="index.php?page=admin_prospects&amp;created_by=<?= (int) $batch['user_id'] ?>">Inventory by person</a>
  </div>
</div>

<div class="card">
  <h2 style="margin:0 0 0.45rem">Sites added this day</h2>
  <p class="help" style="margin-top:0">
    Edit this teammate’s daily history list. Changes <strong>autosave</strong>.
    Cut/copy with your keyboard; <strong>Undo</strong>/<strong>Redo</strong> while you stay on this page.
    By default only history changes — Our database is untouched unless you check the option below.
  </p>
  <div
    class="domains-paste"
    id="history_sites_shell"
    data-post-url="<?= h($base) ?>"
  >
    <div class="domains-paste-head">
      <label for="history_sites_text">Sites</label>
      <div class="sites-list-actions">
        <button type="button" class="btn secondary small" id="history_undo_btn" disabled>Undo</button>
        <button type="button" class="btn secondary small" id="history_redo_btn" disabled>Redo</button>
        <button type="button" class="btn secondary small" id="history_copy_all">Copy all</button>
      </div>
    </div>
    <textarea
      id="history_sites_text"
      class="inventory-box"
      rows="16"
      spellcheck="false"
      aria-label="Site adding history domains"
      data-no-draft
    ><?= h($sitesText) ?></textarea>
    <p class="muted" style="margin:0.35rem 0 0">
      <span id="history_footer_count"><?= (int) $total ?> site<?= $total === 1 ? '' : 's' ?></span>
      <span id="history_autosave_label" class="help" style="margin-left:0.5rem"></span>
    </p>
    <p class="help" id="history_list_status" hidden></p>
  </div>
  <label style="font-weight:500;margin-top:0.75rem;display:flex;gap:0.45rem;align-items:flex-start">
    <input type="checkbox" id="history_also_remove_db" value="1" style="margin-top:0.2rem">
    <span>When removing sites from this list, also delete them from <strong>Our database</strong> for <?= h($batch['country'] ?: 'this country') ?>.</span>
  </label>
</div>

<div class="card" style="margin-top:1rem">
  <h2 style="margin:0 0 0.45rem">Day details</h2>
  <form method="post" action="<?= h($base) ?>" autocomplete="off">
    <input type="hidden" name="action" value="save_meta">
    <div class="form-grid">
      <?= render_country_typeahead((string) ($batch['country'] ?? ''), [
          'id' => 'history_country',
          'label' => 'Country',
          'required' => false,
          'optional' => true,
      ]) ?>
      <div class="full">
        <label for="history_notes">Notes</label>
        <textarea id="history_notes" name="notes" rows="2"><?= h((string) ($batch['notes'] ?? '')) ?></textarea>
      </div>
    </div>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn" type="submit">Save details</button>
    </p>
  </form>
</div>

<?php if ($items): ?>
<details class="card" style="margin-top:1rem">
  <summary>Added timestamps</summary>
  <div class="table-wrap" style="margin-top:0.75rem">
    <table>
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
  </div>
</details>
<?php endif; ?>

<div class="card" style="margin-top:1rem">
  <h2 style="margin:0 0 0.45rem">Delete this day</h2>
  <p class="help" style="margin-top:0">Removes the whole history day for <?= h($person) ?> on <?= h($batch['batch_date']) ?>.</p>
  <form method="post" action="<?= h($base) ?>"
        onsubmit="return confirm('Delete this Site adding history day for <?= h($person) ?> (<?= h($batch['batch_date']) ?>)?');">
    <input type="hidden" name="action" value="delete_day">
    <label style="font-weight:500;display:flex;gap:0.45rem;align-items:flex-start;margin-bottom:0.75rem">
      <input type="checkbox" name="also_remove_db" value="1" style="margin-top:0.2rem">
      <span>Also remove these sites from Our database (<?= h($batch['country'] ?: 'country') ?>).</span>
    </label>
    <button class="btn danger" type="submit">Delete history day</button>
  </form>
</div>
<?= sites_form_script_tag() ?>
<script src="<?= h(script_asset_url('js/prospect-batch-sheet.js')) ?>" defer></script>
<?php render_footer('admin'); ?>
