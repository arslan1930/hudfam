<?php
/**
 * Admin · one Site adding history day — edit domains (copy/cut/undo), delete day.
 */
$user = require_admin();

$id = (int) get('id');
$batch = get_prospect_batch($id);
if (!$batch) {
    flash('error', 'Added sites day not found.');
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

render_header('Added sites · ' . $batch['batch_date'], 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Sites Data', 'href' => 'index.php?page=admin_prospects'],
    ['label' => 'Added sites', 'href' => 'index.php?page=admin_prospect_batches'],
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
    <a class="btn secondary" href="index.php?page=admin_prospect_batches&amp;user=<?= (int) $batch['user_id'] ?>">This teammate</a>
    <a class="btn secondary" href="index.php?page=admin_prospect_batches">All added sites</a>
    <?php
      $batchCountry = canonicalize_country_name(trim((string) ($batch['country'] ?? '')));
      if ($batchCountry !== ''):
    ?>
      <a class="btn" href="index.php?page=admin_prospects&amp;country=<?= urlencode($batchCountry) ?>&amp;created_by=<?= (int) $batch['user_id'] ?>">Countries · <?= h($batchCountry) ?></a>
    <?php else: ?>
      <a class="btn secondary" href="index.php?page=admin_prospects&amp;created_by=<?= (int) $batch['user_id'] ?>">Countries · this person</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <?php if (!empty($batch['notes'])): ?>
    <p class="help"><?= h($batch['notes']) ?></p>
  <?php endif; ?>
  <p class="help">Saved in Countries and in this teammate’s daily add history.</p>
  <?php if ($domains): ?>
    <textarea class="inventory-box" rows="16" readonly><?= h(implode("\n", $domains)) ?></textarea>
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
  <?php else: ?>
    <p class="muted">No domains recorded for this day.</p>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:1rem">
  <h2 style="margin:0 0 0.45rem">Delete this day</h2>
  <form method="post" action="<?= h($base) ?>"
        onsubmit="return confirm('Delete Site adding history for <?= h($batch['batch_date']) ?>?');">
    <input type="hidden" name="action" value="delete_day">
    <label style="font-weight:500;display:flex;gap:0.45rem;align-items:flex-start">
      <input type="checkbox" name="also_remove_db" value="1" style="margin-top:0.2rem">
      <span>Also remove these sites from <strong>Our database</strong> for <?= h($batch['country'] ?: 'this country') ?>.</span>
    </label>
    <p class="actions" style="margin-top:0.85rem">
      <button class="btn danger" type="submit">Delete day</button>
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

<script src="<?= h(script_url('js/prospect-batch-sheet.js')) ?>" defer></script>
<?php render_footer('admin'); ?>
