<?php
$user = require_team();
ensure_email_campaign_schema();

$sq = trim((string) get('sq'));
$paste = '';
$quickResult = null;
$lookup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'quick_cut') {
        $paste = (string) post('emails');
        $status = (string) post('status');
        if (!in_array($status, ['replied', 'dealing', 'do_not_email'], true)) {
            $status = 'replied';
        }
        $quickResult = email_campaign_quick_cut($paste, $status, $user, trim((string) post('notes')));
        $parts = [];
        if ($quickResult['cut'] > 0) {
            $parts[] = "Cut {$quickResult['cut']} from the Ready send list (" . (email_campaign_statuses()[$status] ?? $status) . ').';
        }
        if ($quickResult['already'] > 0) {
            $parts[] = "{$quickResult['already']} already cut earlier.";
        }
        if ($quickResult['missing']) {
            $parts[] = count($quickResult['missing']) . ' email(s) not found in campaign sheets.';
        }
        if (!$parts) {
            flash('error', 'Paste at least one valid email address.');
        } else {
            flash($quickResult['cut'] > 0 ? 'ok' : 'error', implode(' ', $parts));
        }
        // Stay on page with results (no redirect) so Team sees confirmation table
    } elseif ($action === 'set_status') {
        $id = (int) post('id');
        $st = (string) post('status');
        if (!in_array($st, ['replied', 'dealing', 'do_not_email'], true)) {
            flash('error', 'Invalid status.');
        } else {
            email_campaign_set_status($id, $st, $user, trim((string) post('notes')));
            flash('ok', 'Marked — cut from future email sends.');
        }
        redirect('index.php?page=team_email_search&sq=' . urlencode((string) post('sq')));
    }
}

if ($sq !== '' && $quickResult === null) {
    $lookup = lookup_email_campaign($sq);
}

render_header('Cut replied emails', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Email campaigns', 'href' => 'index.php?page=team_email_campaigns'],
    ['label' => 'Quick cut'],
]); ?>
<div class="topbar">
  <div>
    <h1>Paste email → cut from send list</h1>
    <p class="muted">
      Paste only the email(s) that replied or you are dealing with.
      We confirm the match and <strong>remove them from the Ready pass</strong> automatically (record stays).
    </p>
  </div>
  <a class="btn secondary" href="index.php?page=team_email_campaigns">Country sheets</a>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="quick_cut">
  <label for="emails">Email address(es)</label>
  <textarea id="emails" name="emails" rows="5" required autofocus
    placeholder="editor@site.com&#10;hello@other.de"><?= h($paste) ?></textarea>
  <p class="help">One email per line (or comma/space separated). No URL needed.</p>
  <div class="form-grid" style="margin-top:0.7rem">
    <div>
      <label>Mark as</label>
      <select name="status">
        <option value="replied" selected>Replied</option>
        <option value="dealing">Dealing</option>
        <option value="do_not_email">Do not email</option>
      </select>
    </div>
    <div class="full">
      <label>Note (optional)</label>
      <input name="notes" placeholder="e.g. asked for price">
    </div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Confirm &amp; cut from Ready list</button>
  </p>
</form>

<?php if ($quickResult && ($quickResult['rows'] || $quickResult['missing'])): ?>
<div class="card">
  <h2>Confirmed</h2>
  <?php if ($quickResult['rows']): ?>
  <table>
    <thead><tr><th>Email</th><th>URL</th><th>Country</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($quickResult['rows'] as $r): ?>
      <tr>
        <td><strong><?= h($r['email']) ?></strong></td>
        <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
        <td><?= h($r['country'] ?: '—') ?></td>
        <td>
          <span class="badge rejected"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span>
          <div class="help"><?= h(email_campaign_status_comment($r['status'])) ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($quickResult['missing']): ?>
    <p class="help" style="margin-top:0.8rem">
      Not found (check spelling or country sheet):
      <strong><?= h(implode(', ', $quickResult['missing'])) ?></strong>
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<details class="card">
  <summary><strong>Or search by email / website first</strong></summary>
  <form class="super-search" method="get" style="margin-top:0.8rem">
    <input type="hidden" name="page" value="team_email_search">
    <label for="sq">Lookup</label>
    <div class="super-search-row">
      <input id="sq" name="sq" value="<?= h($sq) ?>" placeholder="editor@site.com or site.com">
      <button class="btn secondary" type="submit">Search</button>
    </div>
  </form>
  <?php if ($lookup !== null): ?>
  <div style="margin-top:1rem">
    <h2>Results · “<?= h($sq) ?>”</h2>
    <?php if (!$lookup['matches']): ?>
      <p class="muted">Not in email campaign inventory.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>URL</th><th>Email</th><th>Country</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lookup['matches'] as $r): ?>
        <tr>
          <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
          <td><strong><?= h($r['email']) ?></strong></td>
          <td><?= h($r['country'] ?: '—') ?></td>
          <td><span class="badge"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span></td>
          <td>
            <?php if (!in_array($r['status'], email_campaign_cut_statuses(), true)): ?>
              <form method="post" class="actions">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="sq" value="<?= h($sq) ?>">
                <select name="status" style="width:auto">
                  <option value="replied">Replied</option>
                  <option value="dealing">Dealing</option>
                  <option value="do_not_email">Do not email</option>
                </select>
                <button class="btn small" type="submit">Cut</button>
              </form>
            <?php else: ?>
              <span class="help">Already cut</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</details>
<?php render_footer('team'); ?>
