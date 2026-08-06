<?php
$user = require_team();
ensure_extract_schema();

$country = canonicalize_country_name(trim((string) (post('country') ?: get('country'))));
$token = trim((string) get('token'));

// Form from queue posts here (often target=_blank): prepare claim, then open via redirect.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'claim_open') {
    $ids = post('ids');
    if (!is_array($ids)) {
        $ids = [];
    }
    try {
        if ($country === '') {
            flash('error', 'Select a country first.');
            redirect('index.php?page=team_extract_queue');
        }
        $claim = extract_claim_prepare($ids, $user, $country);
        redirect('index.php?page=team_extract_work&token=' . urlencode($claim['token']));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('index.php?page=team_extract_queue&country=' . urlencode($country));
    }
}

$domains = [];
$openedCountry = '';
$error = '';

if ($token !== '') {
    try {
        $opened = extract_claim_open($token, $user);
        $domains = $opened['domains'];
        $openedCountry = canonicalize_country_name((string) $opened['country']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$openUrls = [];
foreach ($domains as $d) {
    $d = strtolower(trim((string) $d));
    if ($d === '') {
        continue;
    }
    $openUrls[] = 'https://' . $d;
}

render_header('Claimed sites', 'team');
?>
<div class="topbar">
  <div>
    <h1>Opened from Block 1</h1>
    <p class="muted">These sites left Block 1 when this tab loaded. Paste your final list into Block 2 when extraction is done.</p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_extract_queue<?= $openedCountry !== '' ? '&country=' . urlencode($openedCountry) : '' ?>">Back to Block 1</a>
    <a class="btn" href="index.php?page=team_extract_final<?= $openedCountry !== '' ? '&country=' . urlencode($openedCountry) : '' ?>">Paste into Block 2</a>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="card empty-state"><p><?= h($error) ?></p></div>
<?php elseif ($domains === []): ?>
  <div class="card empty-state">
    <p>No claim loaded. Go to Claim &amp; extract, select sites, and open them in a new tab.</p>
    <a class="btn" href="index.php?page=team_extract_queue">Open Block 1 queue</a>
  </div>
<?php else: ?>
  <div class="card">
    <p class="flash" style="margin-top:0">
      Removed <?= count($domains) ?> site(s) from Block 1<?= $openedCountry !== '' ? ' · ' . h($openedCountry) : '' ?>.
      If pop-ups were blocked, use the links below.
    </p>
    <ul style="margin:0.75rem 0 0;padding-left:1.2rem">
      <?php foreach ($domains as $i => $d): ?>
        <li style="margin:0.3rem 0">
          <strong><?= h($d) ?></strong>
          · <a href="<?= h($openUrls[$i] ?? ('https://' . $d)) ?>" target="_blank" rel="noopener">Open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <script>
    (function () {
      var urls = <?= json_encode(array_values($openUrls), JSON_UNESCAPED_SLASHES) ?>;
      urls.forEach(function (u) {
        try { window.open(u, '_blank', 'noopener'); } catch (e) {}
      });
    })();
  </script>
<?php endif; ?>
<?php render_footer('team'); ?>
