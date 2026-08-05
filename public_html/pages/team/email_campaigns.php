<?php
$user = require_team();
ensure_email_campaign_schema();

$sheet = (string) get('sheet', 'all');
$status = (string) get('status');
$q = trim((string) get('q'));
$pageNum = max(1, (int) get('p', 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'set_status') {
    $id = (int) post('id');
    $st = (string) post('status');
    if (!in_array($st, ['replied', 'dealing', 'do_not_email'], true)) {
        flash('error', 'Team can mark Replied, Dealing, or Do not email only.');
    } else {
        email_campaign_set_status($id, $st, $user, trim((string) post('notes')));
        flash('ok', 'Contact cut from future send lists (' . (email_campaign_statuses()[$st] ?? $st) . ').');
    }
    redirect('index.php?page=team_email_campaigns&sheet=' . urlencode($sheet) . ($q ? '&q=' . urlencode($q) : ''));
}

$emptyCountry = $sheet === '_none';
$countryFilter = ($sheet !== 'all' && $sheet !== '_none' && $sheet !== '') ? $sheet : '';
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
$sheetLabel = $sheet === 'all' ? 'All countries' : ($sheet === '_none' ? 'No country' : $sheet);
$qs = http_build_query(array_filter([
    'page' => 'team_email_campaigns',
    'sheet' => $sheet !== 'all' ? $sheet : '',
    'status' => $status,
    'q' => $q,
], fn($v) => $v !== '' && $v !== null));

render_header('Email campaigns', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Email campaigns'],
]); ?>
<div class="topbar">
  <div>
    <h1>Email campaign sheets</h1>
    <p class="muted">
      When someone <strong>replies</strong> or you are <strong>dealing</strong> with them, mark them here so Admin does not email them again.
      Records stay — they are only cut from the Ready send list.
    </p>
  </div>
  <a class="btn" href="index.php?page=team_email_search">Search contacts</a>
</div>

<div class="card">
  <div class="sheet-tabs">
    <a class="<?= $sheet === 'all' ? 'active' : '' ?>" href="index.php?page=team_email_campaigns&amp;sheet=all">All</a>
    <?php foreach ($sheets as $sh): ?>
      <?php $key = $sh['country'] === '' ? '_none' : $sh['country']; ?>
      <a class="<?= $sheet === $key ? 'active' : '' ?>" href="index.php?page=team_email_campaigns&amp;sheet=<?= urlencode($key) ?>">
        <?= h($sh['country'] !== '' ? $sh['country'] : 'No country') ?>
        <span class="sheet-count"><?= (int) $sh['total'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_email_campaigns">
  <input type="hidden" name="sheet" value="<?= h($sheet) ?>">
  <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="email or url…"></div>
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
  <p class="muted" style="margin-bottom:0.7rem"><strong><?= h($sheetLabel) ?></strong> · <?= $total ?> contact(s)</p>
  <table>
    <thead>
      <tr><th>URL</th><th>Email</th><th>Country</th><th>Status</th><th>Cut from list</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
        <td><strong><?= h($r['email']) ?></strong></td>
        <td><?= h($r['country'] ?: '—') ?></td>
        <td><span class="badge"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span></td>
        <td>
          <?php if (in_array($r['status'], email_campaign_cut_statuses(), true)): ?>
            <span class="help"><?= h(email_campaign_status_comment($r['status'])) ?></span>
          <?php else: ?>
            <form method="post" class="actions">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <select name="status" style="width:auto">
                <option value="replied">Replied</option>
                <option value="dealing">Dealing</option>
                <option value="do_not_email">Do not email</option>
              </select>
              <input name="notes" placeholder="note…" style="width:8rem">
              <button class="btn small" type="submit">Cut from sends</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="5" class="muted">No contacts yet. Admin fills country sheets daily.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:0.8rem">
    <?php if ($pageNum > 1): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum - 1 ?>">Prev</a><?php endif; ?>
    <span>Page <?= $pageNum ?> / <?= $pages ?></span>
    <?php if ($pageNum < $pages): ?><a href="?<?= h($qs) ?>&amp;p=<?= $pageNum + 1 ?>">Next</a><?php endif; ?>
  </div>
</div>
<?php render_footer('team'); ?>
