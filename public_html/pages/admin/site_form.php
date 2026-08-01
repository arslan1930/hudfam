<?php
$user = require_admin();
$id = (int) get('id');
$site = [
    'domain' => '', 'url' => '', 'region' => '', 'country' => '', 'niche' => '', 'language' => '',
    'dr' => '', 'da' => '', 'traffic' => '',
    'publisher_quote_price' => '', 'publisher_quote_date' => '',
    'backlink_price' => '', 'banner_price_yearly' => '',
    'currency' => 'EUR', 'status' => 'draft', 'publisher_email' => '', 'outreach_notes' => '',
    'warning_flags' => '', 'assigned_to' => '', 'primary_project_id' => '',
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM sites WHERE id=?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if ($found) {
        $site = $found;
    }
}
$teamUsers = db()->query("SELECT id, username FROM users WHERE role='team' AND is_active=1 ORDER BY username")->fetchAll();
$countryOptions = list_countries(null, true);
$history = [];
if ($id) {
    $h = db()->prepare(
        "SELECT pi.*, p.name project_name FROM pitch_items pi
         JOIN pitches ph ON ph.id=pi.pitch_id
         JOIN projects p ON p.id=ph.project_id
         WHERE pi.site_id=? ORDER BY pi.updated_at DESC"
    );
    $h->execute([$id]);
    $history = $h->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = strtolower(trim((string) post('domain')));
    $status = (string) post('status');
    $price = trim((string) post('backlink_price'));
    $price = $price === '' ? null : $price;
    $quote = trim((string) post('publisher_quote_price'));
    $quote = $quote === '' ? null : $quote;
    $quoteDate = trim((string) post('publisher_quote_date'));
    if ($domain === '') {
        flash('error', 'Domain is required.');
    } elseif ($status === 'agreed' && $price === null) {
        flash('error', 'Agreed price is required before status Agreed.');
    } else {
        $data = [
            $domain,
            trim((string) post('url')),
            (string) post('region'),
            trim((string) post('country')),
            trim((string) post('niche')),
            trim((string) post('language')),
            trim((string) post('dr')) === '' ? null : (int) post('dr'),
            trim((string) post('da')) === '' ? null : (int) post('da'),
            trim((string) post('traffic')) === '' ? null : (int) post('traffic'),
            $quote,
            $quoteDate !== '' ? $quoteDate : null,
            $price,
            trim((string) post('banner_price_yearly')) === '' ? null : post('banner_price_yearly'),
            trim((string) post('currency')) ?: 'EUR',
            $status,
            trim((string) post('publisher_email')),
            trim((string) post('outreach_notes')),
            trim((string) post('warning_flags')),
            post('assigned_to') === '' ? null : (int) post('assigned_to'),
        ];
        if ($id) {
            $data[] = $id;
            db()->prepare(
                'UPDATE sites SET domain=?, url=?, region=?, country=?, niche=?, language=?, dr=?, da=?, traffic=?,
                 publisher_quote_price=?, publisher_quote_date=?, backlink_price=?, banner_price_yearly=?, currency=?,
                 status=?, publisher_email=?, outreach_notes=?, warning_flags=?, assigned_to=? WHERE id=?'
            )->execute($data);
        } else {
            $data[] = $user['id'];
            db()->prepare(
                'INSERT INTO sites (domain, url, region, country, niche, language, dr, da, traffic,
                 publisher_quote_price, publisher_quote_date, backlink_price, banner_price_yearly, currency,
                 status, publisher_email, outreach_notes, warning_flags, assigned_to, primary_project_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)'
            )->execute($data);
            $id = (int) db()->lastInsertId();
        }
        if (post('reset_agreed') === '1' && $price !== null) {
            db()->prepare("UPDATE sites SET status='agreed' WHERE id=?")->execute([$id]);
        }
        flash('ok', 'Site saved.');
        redirect('index.php?page=admin_site_form&id=' . $id);
    }
}

render_header($id ? $site['domain'] : 'Add site', 'admin');
?>
<div class="topbar">
  <div><h1><?= $id ? h($site['domain']) : 'Add inventory site' ?></h1></div>
  <?php if ($id): ?>
  <form method="post" class="actions">
    <?php foreach ($site as $k => $v): if (is_array($v)) {
        continue;
    } ?>
      <input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="reset_agreed" value="1">
    <button class="btn secondary" type="submit">Reset to Agreed</button>
  </form>
  <?php endif; ?>
</div>
<div class="grid" style="grid-template-columns:2fr 1fr">
<div class="card">
<form method="post">
  <div class="form-grid">
    <div><label>Domain</label><input name="domain" value="<?= h($site['domain']) ?>" required></div>
    <div><label>URL</label><input name="url" value="<?= h($site['url']) ?>"></div>
    <div><label>Region</label>
      <select name="region">
        <option value="">—</option>
        <?php foreach (regions() as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($site['region'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Country</label>
      <select name="country">
        <option value="">—</option>
        <?php foreach ($countryOptions as $c): ?>
          <option value="<?= h($c['name']) ?>" <?= ($site['country'] ?? '') === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Language</label><input name="language" value="<?= h($site['language']) ?>"></div>
    <div><label>Niche</label><input name="niche" value="<?= h($site['niche']) ?>"></div>
    <div><label>DR</label><input name="dr" value="<?= h((string) $site['dr']) ?>"></div>
    <div><label>DA</label><input name="da" value="<?= h((string) $site['da']) ?>"></div>
    <div><label>Traffic</label><input name="traffic" value="<?= h((string) $site['traffic']) ?>"></div>
    <div><label>Publisher quote price</label><input name="publisher_quote_price" value="<?= h((string) ($site['publisher_quote_price'] ?? '')) ?>"></div>
    <div><label>Quote date</label><input type="date" name="publisher_quote_date" value="<?= h((string) ($site['publisher_quote_date'] ?? '')) ?>"></div>
    <div><label>Agreed price</label><input name="backlink_price" value="<?= h((string) $site['backlink_price']) ?>"></div>
    <div><label>Banner / year</label><input name="banner_price_yearly" value="<?= h((string) $site['banner_price_yearly']) ?>"></div>
    <div><label>Currency</label><input name="currency" value="<?= h($site['currency']) ?>"></div>
    <div><label>Status</label>
      <select name="status">
        <?php foreach (site_statuses() as $code => $label): ?>
          <option value="<?= $code ?>" <?= $site['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Assigned to</label>
      <select name="assigned_to">
        <option value="">—</option>
        <?php foreach ($teamUsers as $tu): ?>
          <option value="<?= (int) $tu['id'] ?>" <?= (string) $site['assigned_to'] === (string) $tu['id'] ? 'selected' : '' ?>><?= h($tu['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Publisher / blogger email</label><input name="publisher_email" value="<?= h($site['publisher_email']) ?>"></div>
    <div class="full"><label>Notes</label><textarea name="outreach_notes" rows="2"><?= h($site['outreach_notes']) ?></textarea></div>
    <div class="full"><label>Warning flags</label><input name="warning_flags" value="<?= h($site['warning_flags']) ?>"></div>
  </div>
  <p class="help">Publisher quote = price from website owner. Agreed price = final catalog price.</p>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
</form>
</div>
<div class="card">
  <h2>Permanent history</h2>
  <?php foreach ($history as $item): ?>
    <div class="history-item">
      <strong><?= h($item['project_name']) ?></strong><br>
      <?= badge($item['item_status']) ?>
      <?php if ($item['reject_reason_code']): ?> · <?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
      <div class="muted"><?= h($item['reject_comment'] ?: $item['client_notes']) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$history): ?><p class="muted">No history yet.</p><?php endif; ?>
</div>
</div>
<?php render_footer('admin'); ?>
