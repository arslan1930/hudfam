<?php
$user = require_team();
ensure_email_campaign_schema();
$sq = trim((string) get('sq'));
$lookup = $sq !== '' ? lookup_email_campaign($sq) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'set_status') {
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

render_header('Email search', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Email campaigns', 'href' => 'index.php?page=team_email_campaigns'],
    ['label' => 'Search'],
]); ?>
<div class="topbar">
  <div>
    <h1>Search email campaign contacts</h1>
    <p class="muted">Find a URL or email across all country sheets before you (or Admin) mail them again.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_email_campaigns">All sheets</a>
</div>

<form class="card super-search" method="get">
  <input type="hidden" name="page" value="team_email_search">
  <label for="sq">Email or website</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($sq) ?>" autofocus placeholder="editor@site.com or site.com">
    <button class="btn" type="submit">Search</button>
  </div>
</form>

<?php if ($lookup !== null): ?>
<div class="card">
  <h2>Results · “<?= h($sq) ?>”</h2>
  <?php if (!$lookup['matches']): ?>
    <p class="muted">Not in email campaign inventory.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>URL</th><th>Email</th><th>Country</th><th>Status / comment</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lookup['matches'] as $r): ?>
      <tr>
        <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
        <td><strong><?= h($r['email']) ?></strong></td>
        <td><?= h($r['country'] ?: '—') ?></td>
        <td>
          <span class="badge"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span>
          <div class="help"><?= h(email_campaign_status_comment($r['status'])) ?></div>
        </td>
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
              <button class="btn small" type="submit">Cut from sends</button>
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
<?php render_footer('team'); ?>
