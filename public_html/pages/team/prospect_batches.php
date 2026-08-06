<?php
$user = require_team();
// Admins see all batches; team sees own (admins collaborating may want all — show all for admin, own for team)
$batches = [];
try {
    $batches = is_admin($user) ? list_prospect_batches(null, 100) : list_prospect_batches((int) $user['id'], 100);
} catch (Throwable $e) {
    flash('error', 'Prospects database tables are missing or broken. Open upgrade.php once, then reload Dated batches.');
}

render_header('Added sites', 'team');
?>
<div class="topbar">
  <div>
    <h1>Added sites</h1>
    <p class="muted">Sites you added, by day.</p>
  </div>
  <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add</a>
</div>

<div class="date-legend" aria-label="Date highlights">
  <span class="date-legend-item"><span class="date-legend-swatch holiday" aria-hidden="true"></span> Sunday · holiday</span>
</div>

<div class="card">
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Teammate</th>
        <th>Sites</th>
        <th>Country / lang</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b):
        $ymd = (string) $b['batch_date'];
        $isSunday = is_sunday_holiday_date($ymd);
        $weekday = batch_weekday_label($ymd);
        $rowClass = $isSunday ? 'row-holiday' : '';
    ?>
      <tr class="<?= h($rowClass) ?>">
        <td>
          <strong><?= h($ymd) ?></strong>
          <?php if ($weekday !== ''): ?>
            <span class="day-meta"><?= h($weekday) ?><?= $isSunday ? ' · holiday' : '' ?></span>
          <?php endif; ?>
          <?php if ($isSunday): ?>
            <span class="badge holiday">Holiday</span>
          <?php endif; ?>
        </td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?> · <?= h($b['language'] ?: '—') ?></td>
        <td><a class="btn small" href="index.php?page=team_prospect_batch&id=<?= (int) $b['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-state">
    <p>No batches yet.</p>
    <a class="btn" href="index.php?page=team_prospect_check">Filter & add sites</a>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('team'); ?>
