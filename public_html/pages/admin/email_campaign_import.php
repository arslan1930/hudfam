<?php
$user = require_admin();
ensure_email_campaign_schema();
$country = trim((string) (post('country') ?: get('country')));
$raw = (string) post('raw');
$countryOptions = list_countries(null, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($country === '') {
        flash('error', 'Choose a country sheet.');
    } elseif ($action === 'import_paste') {
        $rows = parse_email_campaign_paste($raw);
        if (!$rows) {
            flash('error', 'Paste lines as: url, email');
        } else {
            $res = email_campaign_import_rows($rows, $country, $user);
            flash(
                'ok',
                "Imported to {$country}: {$res['inserted']} new, {$res['updated']} updated ready, {$res['skipped']} skipped."
            );
            redirect('index.php?page=admin_email_campaigns&sheet=' . urlencode($country));
        }
    } elseif ($action === 'import_csv' && !empty($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $header = fgetcsv($fh) ?: [];
        $map = [];
        foreach ($header as $i => $h) {
            $map[strtolower(trim((string) $h))] = $i;
        }
        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            $url = $line[$map['url'] ?? $map['domain'] ?? 0] ?? '';
            $email = $line[$map['email'] ?? 1] ?? '';
            $notes = $line[$map['notes'] ?? -1] ?? '';
            $rows[] = ['url' => $url, 'email' => $email, 'notes' => $notes, 'country' => $country];
        }
        fclose($fh);
        $res = email_campaign_import_rows($rows, $country, $user);
        flash(
            'ok',
            "CSV import to {$country}: {$res['inserted']} new, {$res['updated']} updated, {$res['skipped']} skipped."
        );
        redirect('index.php?page=admin_email_campaigns&sheet=' . urlencode($country));
    }
}

render_header('Import email sheet', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Email campaigns', 'href' => 'index.php?page=admin_email_campaigns'],
    ['label' => 'Import'],
]); ?>
<div class="topbar">
  <div>
    <h1>Import country email sheet</h1>
    <p class="muted">Two columns: <strong>URL</strong> and <strong>email</strong>. Emails are unique globally — duplicates / already cut contacts are skipped.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_email_campaigns">Back</a>
</div>

<form class="card" method="post">
  <input type="hidden" name="action" value="import_paste">
  <label>Country sheet</label>
  <select name="country" required>
    <option value="">—</option>
    <?php foreach ($countryOptions as $c): ?>
      <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label>Paste (url, email — one per line)</label>
  <textarea name="raw" rows="14" required placeholder="https://site1.com, editor@site1.com&#10;site2.de, hello@site2.de"><?= h($raw) ?></textarea>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Import paste</button></p>
</form>

<form class="card" method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="import_csv">
  <h2>Or CSV file</h2>
  <label>Country sheet</label>
  <select name="country" required>
    <option value="">—</option>
    <?php foreach ($countryOptions as $c): ?>
      <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label>CSV (headers: url, email)</label>
  <input type="file" name="csv" accept=".csv,text/csv" required>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Import CSV</button></p>
</form>
<?php render_footer('admin'); ?>
