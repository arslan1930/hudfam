<?php
$user = require_team();
ensure_extract_schema();
$country = canonicalize_country_name(trim((string) get('country')));

$queue = $country !== '' ? extract_queue_list($country) : [];
render_header('Claim & extract', 'team');
?>
<div class="topbar">
  <div>
    <h1>Claim &amp; extract</h1>
    <p class="muted">Team 2 · select Block 1 sites, open them in a new tab. When that tab loads, those sites leave Block 1 (Team 1 can keep adding). Paste finals into Block 2 yourself.</p>
  </div>
  <a class="btn secondary" href="index.php?page=team_extract_final<?= $country !== '' ? '&country=' . urlencode($country) : '' ?>">Paste into Block 2</a>
</div>

<form class="card filters" method="get">
  <input type="hidden" name="page" value="team_extract_queue">
  <div>
    <label>Country</label>
    <?= render_country_select('country', $country, 'country', true) ?>
  </div>
  <button class="btn" type="submit">Show Block 1</button>
</form>

<?php if ($country === ''): ?>
  <div class="card empty-state"><p>Select a country to see sites waiting for extraction.</p></div>
<?php elseif (!$queue): ?>
  <div class="card empty-state"><p>Block 1 is empty for <?= h($country) ?>.</p></div>
<?php else: ?>
<form class="card" method="post" id="claim_form" target="_blank" action="index.php?page=team_extract_work"
  onsubmit="setTimeout(function(){ window.location.reload(); }, 800);">
  <input type="hidden" name="action" value="claim_open">
  <input type="hidden" name="country" value="<?= h($country) ?>">
  <div class="actions" style="margin-bottom:0.85rem;flex-wrap:wrap;gap:0.6rem;align-items:center">
    <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
      <input type="checkbox" id="select_all_queue"> Select all
    </label>
    <span class="help"><?= count($queue) ?> in Block 1 · <?= h($country) ?></span>
    <button class="btn" type="submit" id="open_btn" disabled>Open selected in new tab</button>
  </div>
  <table>
    <thead><tr><th style="width:2.2rem"></th><th>Site</th><th>Submitted by</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($queue as $row): ?>
      <tr>
        <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $row['id'] ?>"></td>
        <td><strong><?= h($row['domain']) ?></strong></td>
        <td><?= h($row['full_name'] ?: $row['username'] ?: '—') ?></td>
        <td><?= h(substr((string) $row['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</form>
<script>
(function(){
  var form = document.getElementById('claim_form');
  var all = document.getElementById('select_all_queue');
  var btn = document.getElementById('open_btn');
  function sync(){
    var n = form.querySelectorAll('.row-check:checked').length;
    btn.disabled = n === 0;
    btn.textContent = n ? ('Open ' + n + ' selected in new tab') : 'Open selected in new tab';
  }
  all.addEventListener('change', function(){
    form.querySelectorAll('.row-check').forEach(function(c){ c.checked = all.checked; });
    sync();
  });
  form.querySelectorAll('.row-check').forEach(function(c){ c.addEventListener('change', sync); });
  sync();
})();
</script>
<?php endif; ?>
<?php render_footer('team'); ?>
