<?php
$user = require_team();
$superQ = trim((string) get('sq'));
$lookup = null;
if ($superQ !== '') {
    $lookup = lookup_domain_for_team($superQ);
}

render_header('Search all sheets', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Search all sheets'],
]); ?>
<div class="topbar">
  <div>
    <h1>Search all country sheets</h1>
    <p class="muted">
      One search across every project catalog sheet (by country) and Our inventory.
      Use this <strong>before</strong> Filter &amp; add so you never paste a repeated site.
    </p>
  </div>
  <a class="btn secondary" href="index.php?page=team_projects">Open a project</a>
</div>

<form class="card super-search" method="get" action="index.php">
  <input type="hidden" name="page" value="team_search">
  <label for="sq">Website / domain</label>
  <div class="super-search-row">
    <input id="sq" name="sq" value="<?= h($superQ) ?>" autofocus
           placeholder="example.com">
    <button class="btn" type="submit">Search</button>
    <?php if ($superQ !== ''): ?>
      <a class="btn secondary" href="index.php?page=team_search">Clear</a>
    <?php endif; ?>
  </div>
  <p class="help">
    Results use Admin’s daily sheet data: country, DR, DA, traffic, status, and comments
    (already have it, used, low traffic, inventory, …).
  </p>
</form>

<?php if ($lookup !== null): ?>
  <?php
    $known = $lookup['in_inventory'] || !empty($lookup['catalog_rows']);
    $domain = $lookup['domain'];
  ?>
<div class="card">
  <h2>Result · <?= h($domain !== '' ? $domain : $superQ) ?></h2>
  <?php if ($known): ?>
    <p class="help" style="margin-bottom:0.75rem">Do <strong>not</strong> add this domain again — it is already known.</p>
    <div class="comment-badges">
      <?php foreach ($lookup['comments'] as $c): ?>
        <span class="badge rejected"><?= h($c) ?></span>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">Not found in any country sheet or Our inventory — safe to add in the correct project.</p>
    <p class="actions" style="margin-top:0.8rem">
      <a class="btn" href="index.php?page=team_projects">Choose project → Filter &amp; add</a>
    </p>
  <?php endif; ?>
</div>

<?php if ($lookup['in_inventory'] && $lookup['inventory']): ?>
<div class="card">
  <h2>Our inventory</h2>
  <table>
    <thead><tr><th>Domain</th><th>Country / lang</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <tr>
        <td><strong><?= h($lookup['inventory']['domain']) ?></strong></td>
        <td><?= h($lookup['inventory']['country'] ?: '—') ?> · <?= h($lookup['inventory']['language'] ?: '—') ?></td>
        <td><?= h($lookup['inventory']['status'] ?: '—') ?></td>
        <td><span class="badge agreed">Inventory</span></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($lookup['catalog_rows']): ?>
<div class="card">
  <h2>Project catalog sheets</h2>
  <table>
    <thead>
      <tr>
        <th>Domain</th><th>Project</th><th>Country sheet</th>
        <th>DR / DA / Traffic</th><th>Quote / Agreed</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lookup['catalog_rows'] as $s): ?>
      <tr>
        <td><strong><?= h($s['domain']) ?></strong></td>
        <td><?= h($s['project_name']) ?></td>
        <td><?= h($s['country'] !== '' ? $s['country'] : 'No country') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?> / <?= h((string) ($s['da'] ?? '—')) ?> / <?= h((string) ($s['traffic'] ?? '—')) ?></td>
        <td>
          <?= money_or_dash($s['publisher_quote_price'] ?? null) ?>
          / <?= money_or_dash($s['backlink_price'] ?? null) ?> <?= h($s['currency'] ?? '') ?>
        </td>
        <td><?= badge($s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($lookup['partial']): ?>
<div class="card">
  <h2>Similar domains</h2>
  <table>
    <thead><tr><th>Domain</th><th>Country</th><th>Language</th><th>DR</th><th>DA</th><th>Traffic</th></tr></thead>
    <tbody>
    <?php foreach ($lookup['partial'] as $s): ?>
      <tr>
        <td><a href="index.php?page=team_search&amp;sq=<?= urlencode($s['domain']) ?>"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['country'] ?: '—') ?></td>
        <td><?= h($s['language'] ?: '—') ?></td>
        <td><?= h((string) ($s['dr'] ?? '—')) ?></td>
        <td><?= h((string) ($s['da'] ?? '—')) ?></td>
        <td><?= h((string) ($s['traffic'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php render_footer('team'); ?>
