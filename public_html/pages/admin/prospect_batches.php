<?php
require_admin();
$batches = list_prospect_batches(null, 150);
$missed = [];
try {
    $missed = list_team_missed_work_days(21);
} catch (Throwable $e) {
    $missed = [];
}
render_header('Added sites', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Added sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Added sites</h1>
    <p class="muted">Who added how many sites each day.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
</div>

<div class="date-legend" aria-label="Date highlights">
  <span class="date-legend-item"><span class="date-legend-swatch holiday" aria-hidden="true"></span> Sunday · holiday</span>
  <span class="date-legend-item"><span class="date-legend-swatch no-work" aria-hidden="true"></span> No sites submitted (workday)</span>
</div>

<div class="card">
  <h2 style="margin-top:0">Daily adds</h2>
  <?php if ($batches): ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
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
        <td><a class="btn small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty-state"><p>No adds yet.</p></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">No work days</h2>
  <p class="muted">Active teammates with no sites submitted on a workday (last 21 days). Sundays are holidays and are not listed here.</p>
  <?php if ($missed): ?>
  <?php
    $missedShown = array_slice($missed, 0, 100);
    $missedExtra = count($missed) - count($missedShown);
  ?>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($missedShown as $m): ?>
      <tr class="row-no-work">
        <td>
          <strong><?= h($m['miss_date']) ?></strong>
          <span class="day-meta"><?= h($m['weekday']) ?></span>
        </td>
        <td><?= h($m['full_name'] ?: $m['username']) ?></td>
        <td><span class="badge no-work">No sites submitted</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($missedExtra > 0): ?>
    <p class="muted">Showing 100 of <?= (int) count($missed) ?> missed days.</p>
  <?php endif; ?>
  <?php else: ?>
  <p class="muted" style="margin:0">Everyone active submitted sites on each workday in this period — or there are no active teammates yet.</p>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
