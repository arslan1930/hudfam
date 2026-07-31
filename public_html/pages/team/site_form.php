<?php
$user = require_team();
$id = (int) get('id');
$projectPrefill = (int) get('project');
$site = [
    'domain'=>'','url'=>'','region'=>'','country'=>'','niche'=>'','language'=>'',
    'dr'=>'','da'=>'','traffic'=>'','backlink_price'=>'','banner_price_yearly'=>'',
    'currency'=>'EUR','status'=>'draft','publisher_email'=>'','outreach_notes'=>'',
    'warning_flags'=>'','assigned_to'=>$user['id'],'primary_project_id'=>$projectPrefill ?: '',
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM sites WHERE id=?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Site not found.');
        redirect('index.php?page=team_sites');
    }
    $canEdit = is_admin($user) || (
        (int) $found['assigned_to'] === (int) $user['id']
        && in_array($found['status'], ['draft', 'negotiating', 'agreed'], true)
    );
    $site = $found;
} else {
    $canEdit = true;
}
if (is_admin($user)) {
    $projects = db()->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
} else {
    $p = db()->prepare(
        "SELECT p.id, p.name FROM projects p JOIN project_members pm ON pm.project_id=p.id
         WHERE pm.user_id=? AND p.status='active' ORDER BY p.name"
    );
    $p->execute([$user['id']]);
    $projects = $p->fetchAll();
}
$history = [];
if ($id) {
    $h = db()->prepare(
        "SELECT pi.*, pr.name project_name FROM pitch_items pi
         JOIN pitches ph ON ph.id=pi.pitch_id JOIN projects pr ON pr.id=ph.project_id
         WHERE pi.site_id=? ORDER BY pi.updated_at DESC"
    );
    $h->execute([$id]);
    $history = $h->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        flash('error', 'This site is locked after Admin sent it.');
        redirect('index.php?page=team_site_form&id=' . $id);
    }
    $domain = strtolower(trim((string) post('domain')));
    $status = (string) post('status');
    if (!in_array($status, ['draft', 'negotiating', 'agreed'], true) && !is_admin($user)) {
        $status = 'draft';
    }
    $price = trim((string) post('backlink_price'));
    $price = $price === '' ? null : $price;
    if ($domain === '') {
        flash('error', 'Domain is required.');
    } elseif ($status === 'agreed' && $price === null) {
        flash('error', 'Agreed price is required before status Agreed.');
    } else {
        $assigned = is_admin($user) && post('assigned_to') !== '' ? (int) post('assigned_to') : (int) $user['id'];
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
            $price,
            trim((string) post('banner_price_yearly')) === '' ? null : post('banner_price_yearly'),
            trim((string) post('currency')) ?: 'EUR',
            $status,
            trim((string) post('publisher_email')),
            trim((string) post('outreach_notes')),
            trim((string) post('warning_flags')),
            $assigned,
            post('primary_project_id') === '' ? null : (int) post('primary_project_id'),
        ];
        if ($id) {
            $data[] = $id;
            db()->prepare(
                'UPDATE sites SET domain=?, url=?, region=?, country=?, niche=?, language=?, dr=?, da=?, traffic=?, backlink_price=?, banner_price_yearly=?, currency=?, status=?, publisher_email=?, outreach_notes=?, warning_flags=?, assigned_to=?, primary_project_id=? WHERE id=?'
            )->execute($data);
        } else {
            $data[] = $user['id'];
            db()->prepare(
                'INSERT INTO sites (domain, url, region, country, niche, language, dr, da, traffic, backlink_price, banner_price_yearly, currency, status, publisher_email, outreach_notes, warning_flags, assigned_to, primary_project_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute($data);
            $id = (int) db()->lastInsertId();
        }
        flash('ok', 'Site saved.');
        $proj = (int) post('primary_project_id');
        if ($proj) {
            redirect('index.php?page=team_project&id=' . $proj . '&tab=sites');
        }
        redirect('index.php?page=team_site_form&id=' . $id);
    }
}

render_header($id ? $site['domain'] : 'Add site', 'team');
?>
<div class="topbar">
  <div>
    <h1><?= $id ? h($site['domain']) : 'Add site' ?></h1>
    <p class="muted"><?= $canEdit ? 'Negotiate via Gmail, then save agreed price here.' : 'Read-only — pipeline controlled by Admin.' ?></p>
  </div>
</div>
<div class="grid" style="grid-template-columns:2fr 1fr">
<div class="card">
<?php if ($canEdit): ?>
<form method="post">
  <div class="form-grid">
    <div><label>Domain</label><input name="domain" value="<?= h($site['domain']) ?>" required></div>
    <div><label>URL</label><input name="url" value="<?= h($site['url']) ?>"></div>
    <div><label>Region</label>
      <select name="region">
        <option value="">—</option>
        <?php foreach (['europe'=>'Europe','north_america'=>'North America','english'=>'English','other'=>'Other'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $site['region']===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Country</label><input name="country" value="<?= h($site['country']) ?>"></div>
    <div><label>Niche</label><input name="niche" value="<?= h($site['niche']) ?>"></div>
    <div><label>Language</label><input name="language" value="<?= h($site['language']) ?>"></div>
    <div><label>DR</label><input name="dr" value="<?= h((string)$site['dr']) ?>"></div>
    <div><label>DA</label><input name="da" value="<?= h((string)$site['da']) ?>"></div>
    <div><label>Traffic</label><input name="traffic" value="<?= h((string)$site['traffic']) ?>"></div>
    <div><label>Backlink price</label><input name="backlink_price" value="<?= h((string)$site['backlink_price']) ?>"></div>
    <div><label>Banner / year</label><input name="banner_price_yearly" value="<?= h((string)$site['banner_price_yearly']) ?>"></div>
    <div><label>Currency</label><input name="currency" value="<?= h($site['currency']) ?>"></div>
    <div><label>Status</label>
      <select name="status">
        <?php foreach (['draft','negotiating','agreed'] as $st): ?>
          <option value="<?= $st ?>" <?= $site['status']===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Project folder</label>
      <select name="primary_project_id">
        <option value="">—</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$site['primary_project_id']===(string)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Publisher email</label><input name="publisher_email" value="<?= h($site['publisher_email']) ?>"></div>
    <div class="full"><label>Notes</label><textarea name="outreach_notes" rows="2"><?= h($site['outreach_notes']) ?></textarea></div>
    <div class="full"><label>Warning flags</label><input name="warning_flags" value="<?= h($site['warning_flags']) ?>"></div>
  </div>
  <p class="help">Status Agreed requires a backlink price.</p>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save site</button></p>
</form>
<?php else: ?>
  <table>
    <tr><th>Country / niche</th><td><?= h($site['country']) ?> · <?= h($site['niche']) ?></td></tr>
    <tr><th>DR / DA / Traffic</th><td><?= h((string)$site['dr']) ?> / <?= h((string)$site['da']) ?> / <?= h((string)$site['traffic']) ?></td></tr>
    <tr><th>Price</th><td><?= money_or_dash($site['backlink_price']) ?> <?= h($site['currency']) ?></td></tr>
    <tr><th>Status</th><td><?= badge($site['status']) ?></td></tr>
    <tr><th>Notes</th><td><?= h($site['outreach_notes'] ?: '—') ?></td></tr>
    <tr><th>Flags</th><td><?= h($site['warning_flags'] ?: '—') ?></td></tr>
  </table>
<?php endif; ?>
</div>
<div class="card">
  <h2>Client results history</h2>
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
<?php render_footer('team'); ?>
