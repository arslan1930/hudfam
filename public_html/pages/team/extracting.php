<?php
$user = require_team();
ensure_extract_schema();

$batches = [];
try {
    $batches = list_extract_batches(200);
} catch (Throwable $e) {
    flash('error', 'Extracting sites tables are missing. Open upgrade.php once, then reload.');
}

render_header('Extracting sites', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Extracting sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Extracting sites</h1>
    <p class="muted">Countries with sites in their <strong>Sites list</strong> appear here. Empty countries hide when you open this page (and are removed after 1 hour) until Filter &amp; add brings them back.</p>
  </div>
  <div class="actions">
    <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
      <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
    <?php else: ?>
      <span class="muted" style="align-self:center">Sites arrive from Site Finding (Filter &amp; add).</span>
    <?php endif; ?>
  </div>
</div>
<?= guide_extracting() ?>

<?php if ($batches): ?>
<div class="card">
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Country</th>
        <th>Sites</th>
        <th>Updated</th>
        <th>Last Push</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h((string) $b['country']) ?></strong></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td class="muted"><?= h((string) ($b['updated_at'] ?? '')) ?></td>
        <td class="muted"><?php
            $lastPush = trim((string) ($b['last_pushed_at'] ?? ''));
            echo $lastPush !== '' ? h(substr($lastPush, 0, 16)) : '—';
        ?></td>
        <td><a class="btn small" href="index.php?page=team_extract_batch&amp;id=<?= (int) $b['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="empty-state" style="min-height:16rem;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem">
    <div>
      <p style="margin:0 0 0.75rem;font-size:1.1rem">Waiting for sites from the team mate</p>
      <p class="muted" style="margin:0 0 1.25rem;max-width:28rem">
        Country batches stay empty until a teammate filters and adds new unique sites.
        Those sites land in the admin country database and here under <strong>Sites list</strong>.
      </p>
      <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
        <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add sites</a>
      <?php else: ?>
        <p class="help" style="margin:0">Ask Site Finding to Filter &amp; add — then countries appear here.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
