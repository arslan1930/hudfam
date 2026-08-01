<?php
require_team();
$grouped = countries_grouped();

// Site counts per country
$counts = [];
foreach (db()->query("SELECT country, COUNT(*) c FROM sites WHERE country <> '' GROUP BY country") as $row) {
    $counts[$row['country']] = (int) $row['c'];
}

render_header('Countries', 'team');
?>
<div class="topbar">
  <div>
    <h1>Country folders</h1>
    <p class="muted">Browse inventory by region → country. Add sites into the catalog, not into projects.</p>
  </div>
  <a class="btn" href="index.php?page=team_site_form">Add site</a>
</div>

<?php foreach ($grouped as $regionCode => $block): ?>
  <?php if (!$block['countries']) {
      continue;
  } ?>
  <div class="card">
    <h2><?= h($block['label']) ?></h2>
    <div class="folders" style="margin-top:0.8rem">
      <?php foreach ($block['countries'] as $c): ?>
        <a class="folder" href="index.php?page=team_country&name=<?= urlencode($c['name']) ?>">
          <h3><?= h($c['name']) ?></h3>
          <p class="muted"><?= h($c['code'] ?: '') ?> · <?= h($c['default_language'] ?: '—') ?></p>
          <p><span class="badge"><?= (int) ($counts[$c['name']] ?? 0) ?> sites</span></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php render_footer('team'); ?>
