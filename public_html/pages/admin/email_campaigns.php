<?php
$user = require_admin();
ensure_email_campaign_schema();

$sheet = (string) get('sheet', 'all');
$status = (string) get('status');
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));
$wave = trim((string) (post('wave') ?: get('wave')));

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'add_one') {
        $country = trim((string) post('country'));
        $rows = [[
            'url' => (string) post('url'),
            'email' => (string) post('email'),
            'notes' => (string) post('notes'),
            'country' => $country,
        ]];
        $res = email_campaign_import_rows($rows, $country, $user);
        if ($res['inserted'] || $res['updated']) {
            flash('ok', 'Contact saved to email campaign sheet.');
        } else {
            flash('error', $res['errors'][0] ?? 'Could not save (duplicate or already cut from list).');
        }
        redirect('index.php?page=admin_email_campaigns&sheet=' . urlencode($sheet !== 'all' ? $sheet : ($country ?: 'all')));
    }
    if ($action === 'set_status') {
        $id = (int) post('id');
        $st = (string) post('status');
        email_campaign_set_status($id, $st, $user, trim((string) post('notes')), $wave);
        flash('ok', 'Status updated.');
        redirect('index.php?page=admin_email_campaigns&sheet=' . urlencode($sheet) . ($status ? '&status=' . urlencode($status) : ''));
    }
    if ($action === 'export_ready') {
        $country = (string) post('country_export');
        $mark = (bool) post('mark_emailed');
        $waveName = trim((string) post('wave')) ?: ('wave-' . date('Y-m-d'));
        $rows = email_campaign_export_ready($country, $mark, $user, $waveName);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="email-campaign-ready-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['url', 'email', 'country', 'domain', 'status', 'campaign_wave']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['url'], $r['email'], $r['country'], $r['domain'],
                $mark ? 'emailed' : $r['status'],
                $mark ? $waveName : $r['campaign_wave'],
            ]);
        }
        fclose($out);
        exit;
    }
}

$emptyCountry = $sheet === '_none';
$countryFilter = '';
if ($sheet === '_none') {
    $countryFilter = '';
} elseif ($sheet !== 'all' && $sheet !== '') {
    $countryFilter = $sheet;
}

$sheets = email_campaign_country_sheets();
$inv = email_campaign_query([
    'q' => $q,
    'status' => $status,
    'country' => $sheet === 'all' ? '' : $countryFilter,
    'empty_country' => $emptyCountry,
], $pageNum, 50);
$rows = $inv['rows'];
$total = $inv['total'];
$pages = $inv['pages'];
$countryOptions = list_countries(null, true);
$sheetLabel = $sheet === 'all' ? 'All countries' : ($sheet === '_none' ? 'No country' : $sheet);
$qs = http_build_query(array_filter([
    'page' => 'admin_email_campaigns',
    'sheet' => $sheet !== 'all' ? $sheet : '',
    'status' => $status,
    'q' => $q,
], fn($v) => $v !== '' && $v !== null));

render_header('Email campaigns', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Email campaigns'],
]); ?>
<div class="topbar">
  <div>
    <h1>Email campaign inventory</h1>
    <p class="muted">
      Country sheets of <strong>URL + email</strong>. Export Ready → send → those stay out of the next list.
      Team marks Replied / Dealing to cut contacts permanently from sends.
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_email_campaign_import&amp;country=<?= urlencode($sheet !== 'all' && $sheet !== '_none' ? $sheet : '') ?>">Import sheet</a>
  </div>
</div>
<?= guide_admin_email_campaigns() ?>

<div class="card">
  <h2>Country sheets</h2>
  <div class="sheet-tabs" style="margin-top:0.5rem">
    <a class="<?= $sheet === 'all' ? 'active' : '' ?>" href="index.php?page=admin_email_campaigns&amp;sheet=all">All</a>
    <?php foreach ($sheets as $sh): ?>
      <?php $key = $sh['country'] === '' ? '_none' : $sh['country']; ?>
      <a class="<?= $sheet === $key ? 'active' : '' ?>" href="index.php?page=admin_email_campaigns&amp;sheet=<?= urlencode($key) ?>">
        <?= h($sh['country'] !== '' ? $sh['country'] : 'No country') ?>
        <span class="sheet-count"><?= (int) $sh['ready'] ?> ready</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$sheets): ?>
    <p class="muted" style="margin-top:0.8rem">No contacts yet. Import a sheet or add one row below.</p>
  <?php endif; ?>
</div>

<div class="grid" style="grid-template-columns:1.2fr 1fr">
  <form class="card" method="post">
    <input type="hidden" name="action" value="export_ready">
    <h2>Export Ready for send</h2>
    <p class="help">Downloads only <strong>Ready</strong> contacts (never Replied / Dealing / Do not email).</p>
    <label>Country sheet</label>
    <select name="country_export">
      <option value="all" <?= $sheet === 'all' ? 'selected' : '' ?>>All countries</option>
      <option value="_none" <?= $sheet === '_none' ? 'selected' : '' ?>>No country</option>
      <?php foreach ($sheets as $sh): if ($sh['country'] === '') continue; ?>
        <option value="<?= h($sh['country']) ?>" <?= $sheet === $sh['country'] ? 'selected' : '' ?>>
          <?= h($sh['country']) ?> (<?= (int) $sh['ready'] ?> ready)
        </option>
      <?php endforeach; ?>
    </select>
    <label>Campaign wave name</label>
    <input name="wave" value="<?= h($wave ?: ('wave-' . date('Y-m-d'))) ?>" placeholder="wave-2026-08-02">
    <label style="font-weight:500;margin-top:0.8rem">
      <input type="checkbox" name="mark_emailed" value="1" checked>
      After export, mark these as <strong>Emailed</strong> (cut from next Ready list)
    </label>
    <p class="actions" style="margin-top:1rem">
      <button class="btn" type="submit">Download Ready CSV</button>
    </p>
  </form>

  <form class="card" method="post">
    <input type="hidden" name="action" value="add_one">
    <h2>Add one contact</h2>
    <label>Country</label>
    <select name="country" required>
      <option value="">—</option>
      <?php foreach ($countryOptions as $c): ?>
        <option value="<?= h($c['name']) ?>" <?= ($sheet !== 'all' && $sheet !== '_none' && $sheet === $c['name']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>URL</label>
    <input name="url" required placeholder="https://example.com">
    <label>Email</label>
    <input name="email" type="email" required placeholder="editor@example.com">
    <label>Notes</label>
    <input name="notes" placeholder="optional">
    <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
  </form>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="admin_email_campaigns">
  <input type="hidden" name="sheet" value="<?= h($sheet) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="email, url…"></div>
  <div><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (email_campaign_statuses() as $code => $label): ?>
        <option value="<?= h($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
  <div class="topbar" style="margin-bottom:0.5rem">
    <p class="muted"><strong><?= h($sheetLabel) ?></strong> · <?= $total ?> contact(s)</p>
  </div>
  <table>
    <thead>
      <tr>
        <th>URL</th><th>Email</th><th>Country</th><th>Status</th>
        <th>Wave / emailed</th><th>Notes</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <?php if ($r['url']): ?>
            <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['domain'] ?: $r['url']) ?></a>
          <?php else: ?>
            <?= h($r['domain'] ?: '—') ?>
          <?php endif; ?>
        </td>
        <td><strong><?= h($r['email']) ?></strong></td>
        <td><?= h($r['country'] ?: '—') ?></td>
        <td><span class="badge <?= h($r['status'] === 'ready' ? 'agreed' : ($r['status'] === 'emailed' ? 'sent' : 'rejected')) ?>"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span></td>
        <td class="help"><?= h($r['campaign_wave'] ?: '—') ?><?php if ($r['last_emailed_at']): ?><br><?= h(substr((string) $r['last_emailed_at'], 0, 16)) ?><?php endif; ?></td>
        <td class="help"><?php $n = (string) ($r['notes'] ?? ''); echo h(strlen($n) > 48 ? substr($n, 0, 45) . '…' : $n); ?></td>
        <td>
          <form method="post" class="actions" style="gap:0.25rem">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <select name="status" style="width:auto;min-width:8rem">
              <?php foreach (email_campaign_statuses() as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $r['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn small" type="submit">Update</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="muted">No contacts in this sheet. <a href="index.php?page=admin_email_campaign_import">Import URL + email</a>.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
