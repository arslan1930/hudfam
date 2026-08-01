<?php
require_team();
$grouped = countries_grouped();
render_header('Countries', 'team');
?>
<div class="topbar">
  <div>
    <h1>Country reference</h1>
    <p class="muted">Country list for filters. Site inventory lives inside each <a href="index.php?page=team_projects">project</a> — Super search is per project.</p>
  </div>
  <a class="btn" href="index.php?page=team_projects">Open projects</a>
</div>

<?php foreach ($grouped as $regionCode => $block): ?>
  <?php if (!$block['countries']) {
      continue;
  } ?>
  <div class="card">
    <h2><?= h($block['label']) ?></h2>
    <div class="folders" style="margin-top:0.8rem">
      <?php foreach ($block['countries'] as $c): ?>
        <div class="folder">
          <h3><?= h($c['name']) ?></h3>
          <p class="muted"><?= h($c['code'] ?: '') ?> · <?= h($c['default_language'] ?: '—') ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php render_footer('team'); ?>
