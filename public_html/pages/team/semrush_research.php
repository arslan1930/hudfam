<?php
/**
 * Site Finding · Semrush Research — country folders with Admin-seeded site names.
 */
$user = require_team();
ensure_semrush_research_schema();

$folders = list_semrush_country_rows();
$isAdmin = is_admin($user);

render_header('Semrush Research', 'team');
render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Semrush Research'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Semrush Research', 'Site names seeded by Admin per country. Open a country sheet to copy, edit, undo/redo, and comment. Countries appear only after Admin adds sites.') ?></h1>
    <p class="muted">
      <?= count($folders) ?> countr<?= count($folders) === 1 ? 'y' : 'ies' ?> with research sites · site names only
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_prospect_check">Filter &amp; add</a>
    <?php if ($isAdmin): ?>
      <a class="btn" href="<?= h(semrush_hub_url(true)) ?>">Admin seed sites</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($folders === []): ?>
<div class="card">
  <div class="empty-state">
    <p>No Semrush Research countries yet.</p>
    <p class="muted">
      <?php if ($isAdmin): ?>
        Go to <a href="<?= h(semrush_hub_url(true)) ?>">Admin · Semrush Research</a> and add site names for a country.
      <?php else: ?>
        When Admin adds site names for a country, it will show up here.
      <?php endif; ?>
    </p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="invoice-list-toolbar" style="margin-bottom:0.75rem">
    <h2 style="margin:0">Countries</h2>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Country</th>
          <th>Sites</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f):
          $c = (string) $f['country'];
          $href = semrush_sheet_url($c, false);
          ?>
        <tr>
          <td><a href="<?= h($href) ?>"><strong><?= h($c) ?></strong></a></td>
          <td><?= (int) $f['total'] ?></td>
          <td class="muted"><?= h(substr((string) $f['updated_at'], 0, 16)) ?></td>
          <td class="actions"><a class="btn small" href="<?= h($href) ?>">Open sheet</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
