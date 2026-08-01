<?php
$user = require_team();
$id = (int) get('id');
$projectId = (int) get('project_id');

$site = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM sites WHERE id=?');
    $stmt->execute([$id]);
    $site = $stmt->fetch();
    if (!$site) {
        flash('error', 'Site not found.');
        redirect('index.php?page=team_projects');
    }
    $projectId = (int) $site['primary_project_id'];
}

if (!$projectId) {
    flash('error', 'Open a project first, then add sites to its inventory.');
    redirect('index.php?page=team_projects');
}

$project = require_project_access($projectId, $user);

if (!$site) {
    $site = [
        'domain' => '', 'url' => '', 'region' => '', 'country' => trim((string) get('country')),
        'niche' => '', 'language' => trim((string) get('language')),
        'dr' => '', 'da' => '', 'traffic' => '',
        'publisher_quote_price' => '', 'publisher_quote_date' => date('Y-m-d'),
        'backlink_price' => '', 'banner_price_yearly' => '',
        'currency' => $project['currency'] ?: 'EUR', 'status' => 'draft',
        'publisher_email' => '', 'our_mailbox' => '', 'our_contact_name' => '',
        'outreach_notes' => '', 'warning_flags' => '', 'assigned_to' => $user['id'],
        'primary_project_id' => $projectId,
    ];
}

$canEdit = !$id || is_admin($user) || (
    (int) $site['assigned_to'] === (int) $user['id']
    && in_array($site['status'], ['draft', 'negotiating', 'agreed'], true)
);

$countryOptions = list_countries(null, true);
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
    $domain = normalize_domain((string) post('domain'));
    $status = (string) post('status');
    if (!in_array($status, ['draft', 'negotiating', 'agreed'], true) && !is_admin($user)) {
        $status = 'draft';
    }
    $agreed = trim((string) post('backlink_price'));
    $agreed = $agreed === '' ? null : $agreed;
    $quote = trim((string) post('publisher_quote_price'));
    $quote = $quote === '' ? null : $quote;
    $quoteDate = trim((string) post('publisher_quote_date'));
    $region = (string) post('region');
    $country = trim((string) post('country'));
    $language = trim((string) post('language'));
    $ourMailbox = trim((string) post('our_mailbox'));
    $ourContact = trim((string) post('our_contact_name'));

    if ($country !== '') {
        foreach ($countryOptions as $c) {
            if (strcasecmp($c['name'], $country) === 0) {
                if ($region === '') {
                    $region = $c['region'];
                }
                if ($language === '' && $c['default_language'] !== '') {
                    $language = $c['default_language'];
                }
                break;
            }
        }
    }

    if ($domain === '') {
        flash('error', 'Domain is required.');
    } elseif ($status === 'agreed' && $agreed === null) {
        flash('error', 'Agreed price is required before status Agreed.');
    } elseif (!$id && !empty(search_inventory_safe_for_team($domain, 1))) {
        flash('error', 'This domain is already in our inventory. Use Super search — do not add it again.');
        redirect('index.php?page=team_search&sq=' . urlencode($domain));
    } else {
        $data = [
            $domain,
            trim((string) post('url')),
            $region,
            $country,
            trim((string) post('niche')),
            $language,
            trim((string) post('dr')) === '' ? null : (int) post('dr'),
            trim((string) post('da')) === '' ? null : (int) post('da'),
            trim((string) post('traffic')) === '' ? null : (int) post('traffic'),
            $quote,
            $quoteDate !== '' ? $quoteDate : null,
            $agreed,
            trim((string) post('banner_price_yearly')) === '' ? null : post('banner_price_yearly'),
            trim((string) post('currency')) ?: 'EUR',
            $status,
            trim((string) post('publisher_email')),
            $ourMailbox,
            $ourContact,
            trim((string) post('outreach_notes')),
            trim((string) post('warning_flags')),
            (int) $user['id'],
            $projectId,
        ];
        try {
            if ($id) {
                $data[] = $id;
                db()->prepare(
                    'UPDATE sites SET domain=?, url=?, region=?, country=?, niche=?, language=?, dr=?, da=?, traffic=?,
                     publisher_quote_price=?, publisher_quote_date=?, backlink_price=?, banner_price_yearly=?, currency=?,
                     status=?, publisher_email=?, our_mailbox=?, our_contact_name=?, outreach_notes=?, warning_flags=?,
                     assigned_to=?, primary_project_id=? WHERE id=?'
                )->execute($data);
            } else {
                $data[] = $user['id'];
                db()->prepare(
                    'INSERT INTO sites (domain, url, region, country, niche, language, dr, da, traffic,
                     publisher_quote_price, publisher_quote_date, backlink_price, banner_price_yearly, currency,
                     status, publisher_email, our_mailbox, our_contact_name, outreach_notes, warning_flags,
                     assigned_to, primary_project_id, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute($data);
                $id = (int) db()->lastInsertId();
            }
            flash('ok', 'Saved to project catalog.');
            redirect('index.php?page=team_project&id=' . $projectId . '&tab=inventory');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uniq_project_domain') || str_contains($e->getMessage(), 'Duplicate')) {
                flash('error', 'This domain is already in this project catalog. Use Super search to find it.');
            } else {
                flash('error', 'Could not save site.');
            }
        }
    }
}

render_header(($id ? $site['domain'] : 'Add site') . ' · ' . $project['name'], 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Projects', 'href' => 'index.php?page=team_projects'],
    ['label' => $project['name'], 'href' => 'index.php?page=team_project&id=' . $projectId . '&tab=inventory'],
    ['label' => $id ? (string) $site['domain'] : 'Add site'],
]); ?>
<div class="topbar">
  <div>
    <h1><?= $id ? h($site['domain']) : 'Add catalog site' ?></h1>
    <p class="muted">Project catalog · <a href="index.php?page=team_project&id=<?= $projectId ?>&tab=inventory"><?= h($project['name']) ?></a></p>
  </div>
  <a class="btn secondary" href="index.php?page=team_project&id=<?= $projectId ?>&tab=inventory">Back to catalog</a>
</div>
<div class="grid" style="grid-template-columns:2fr 1fr">
<div class="card">
<?php if ($canEdit): ?>
<form method="post">
  <fieldset class="form-section">
    <legend>Site metrics</legend>
    <div class="form-grid">
      <div><label>Domain</label><input name="domain" value="<?= h($site['domain']) ?>" required placeholder="example.com"></div>
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
        <select name="country" id="country_select">
          <option value="">—</option>
          <?php foreach ($countryOptions as $c): ?>
            <option value="<?= h($c['name']) ?>"
              data-region="<?= h($c['region']) ?>"
              data-lang="<?= h($c['default_language']) ?>"
              <?= ($site['country'] ?? '') === $c['name'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Language</label><input name="language" id="language_input" value="<?= h($site['language']) ?>"></div>
      <div><label>Niche</label><input name="niche" value="<?= h($site['niche']) ?>"></div>
      <div><label>DR</label><input name="dr" value="<?= h((string) $site['dr']) ?>"></div>
      <div><label>DA</label><input name="da" value="<?= h((string) $site['da']) ?>"></div>
      <div><label>Traffic</label><input name="traffic" value="<?= h((string) $site['traffic']) ?>"></div>
    </div>
  </fieldset>

  <fieldset class="form-section">
    <legend>Pricing &amp; status</legend>
    <div class="form-grid">
      <div><label>Publisher quote price</label><input name="publisher_quote_price" value="<?= h((string) ($site['publisher_quote_price'] ?? '')) ?>"></div>
      <div><label>Quote date</label><input type="date" name="publisher_quote_date" value="<?= h((string) ($site['publisher_quote_date'] ?? '')) ?>"></div>
      <div><label>Agreed price</label><input name="backlink_price" value="<?= h((string) $site['backlink_price']) ?>"></div>
      <div><label>Banner / year</label><input name="banner_price_yearly" value="<?= h((string) $site['banner_price_yearly']) ?>"></div>
      <div><label>Currency</label><input name="currency" value="<?= h($site['currency']) ?>"></div>
      <div><label>Site status</label>
        <select name="status">
          <?php foreach (['draft', 'negotiating', 'agreed'] as $st): ?>
            <option value="<?= $st ?>" <?= $site['status'] === $st ? 'selected' : '' ?>><?= h(status_label($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </fieldset>

  <fieldset class="form-section">
    <legend>Outreach</legend>
    <div class="form-grid">
      <div><label>Blogger / publisher email</label><input name="publisher_email" value="<?= h($site['publisher_email']) ?>" placeholder="blogger@site.com"></div>
      <div><label>Our Gmail / mailbox</label><input name="our_mailbox" value="<?= h($site['our_mailbox'] ?? '') ?>" placeholder="outreach1@gmail.com" required></div>
      <div><label>Our contact name</label><input name="our_contact_name" value="<?= h($site['our_contact_name'] ?? '') ?>" placeholder="Name on that inbox / signature" required></div>
      <div class="full"><label>Notes</label><textarea name="outreach_notes" rows="2"><?= h($site['outreach_notes']) ?></textarea></div>
      <div class="full"><label>Warning flags</label><input name="warning_flags" value="<?= h($site['warning_flags']) ?>"></div>
    </div>
  </fieldset>
  <p class="help"><strong>Our Gmail / mailbox</strong> = which of your inboxes got the blogger’s reply, so teammates know where to continue the chat.</p>
  <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save to project catalog</button></p>
</form>
<script>
(function(){
  var sel = document.getElementById('country_select');
  var lang = document.getElementById('language_input');
  var region = document.querySelector('select[name=region]');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    if (region && opt.dataset.region) region.value = opt.dataset.region;
    if (lang && opt.dataset.lang && !lang.value) lang.value = opt.dataset.lang;
  });
})();
</script>
<?php else: ?>
  <table>
    <tr><th>Country / language</th><td><?= h($site['country']) ?> · <?= h($site['language']) ?></td></tr>
    <tr><th>Publisher quote</th><td><?= money_or_dash($site['publisher_quote_price'] ?? null) ?> on <?= h((string) ($site['publisher_quote_date'] ?? '—')) ?></td></tr>
    <tr><th>Agreed price</th><td><?= money_or_dash($site['backlink_price']) ?> <?= h($site['currency']) ?></td></tr>
    <tr><th>Blogger email</th><td><?= h($site['publisher_email'] ?: '—') ?></td></tr>
    <tr><th>Our mailbox</th><td><?= h(($site['our_mailbox'] ?? '') ?: '—') ?> · <?= h(($site['our_contact_name'] ?? '') ?: '—') ?></td></tr>
    <tr><th>Status</th><td><?= badge($site['status']) ?></td></tr>
    <tr><th>Notes</th><td><?= h($site['outreach_notes'] ?: '—') ?></td></tr>
  </table>
<?php endif; ?>
</div>
<div class="card">
  <h2>Client results history</h2>
  <?php foreach ($history as $item): ?>
    <div class="history-item">
      <?= badge($item['item_status']) ?>
      <?php if ($item['reject_reason_code']): ?> · <?= h(reject_reasons()[$item['reject_reason_code']] ?? $item['reject_reason_code']) ?><?php endif; ?>
      <div class="muted"><?= h($item['reject_comment'] ?: $item['client_notes']) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$history): ?><p class="muted">No history yet.</p><?php endif; ?>
</div>
</div>
<?php render_footer('team'); ?>
