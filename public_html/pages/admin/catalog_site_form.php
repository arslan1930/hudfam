<?php
$user = require_admin();
ensure_country_catalog_schema();

$id = (int) get('id');
$site = $id ? country_catalog_get($id) : null;
$countryGroups = country_catalog_countries_grouped();
$country = trim((string) (post('country') ?: get('country') ?: ($site['country'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = trim((string) post('country'));
    $domain = normalize_domain((string) post('domain'));
    if ($country === '' || $domain === '') {
        flash('error', 'Country and domain are required.');
    } else {
        try {
            $res = country_catalog_save([
                'country' => $country,
                'domain' => $domain,
                'url' => (string) post('url'),
                'language' => (string) post('language'),
                'region' => (string) post('region'),
                'niche' => (string) post('niche'),
                'da' => post('da'),
                'dr' => post('dr'),
                'traffic' => post('traffic'),
                'publisher_quote_price' => post('publisher_quote_price'),
                'backlink_price' => post('backlink_price'),
                'currency' => (string) post('currency'),
                'status' => (string) post('status'),
                'order_status' => (string) post('order_status'),
                'client_name' => (string) post('client_name'),
                'admin_comments' => (string) post('admin_comments'),
                'our_mailbox' => (string) post('our_mailbox'),
                'our_contact_name' => (string) post('our_contact_name'),
            ], $user);
            flash('ok', $res['inserted'] ? 'Site added to country catalog.' : 'Site updated.');
            redirect('index.php?page=admin_sites&sheet=' . urlencode($country));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
    $site = array_merge($site ?: [], [
        'country' => $country,
        'domain' => (string) post('domain'),
        'url' => (string) post('url'),
        'language' => (string) post('language'),
        'region' => (string) post('region'),
        'niche' => (string) post('niche'),
        'da' => post('da'),
        'dr' => post('dr'),
        'traffic' => post('traffic'),
        'publisher_quote_price' => post('publisher_quote_price'),
        'backlink_price' => post('backlink_price'),
        'currency' => (string) post('currency'),
        'status' => (string) post('status'),
        'order_status' => (string) post('order_status'),
        'inventory_client_name' => (string) post('client_name'),
        'admin_comments' => (string) post('admin_comments'),
        'our_mailbox' => (string) post('our_mailbox'),
        'our_contact_name' => (string) post('our_contact_name'),
    ]);
}

$site = $site ?: [
    'domain' => '', 'url' => '', 'country' => $country, 'language' => '', 'region' => '',
    'niche' => '', 'da' => '', 'dr' => '', 'traffic' => '',
    'publisher_quote_price' => '', 'backlink_price' => '', 'currency' => 'EUR',
    'status' => 'draft', 'order_status' => '', 'inventory_client_name' => '',
    'admin_comments' => '', 'our_mailbox' => '', 'our_contact_name' => '',
];

$title = $id ? 'Edit catalog site' : 'Add catalog site';
render_header($title, 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Catalog', 'href' => 'index.php?page=admin_sites'],
    ['label' => $country !== '' ? $country : 'Add site', 'href' => $country !== '' ? 'index.php?page=admin_sites&sheet=' . urlencode($country) : null],
    ['label' => $id ? ($site['domain'] ?: 'Edit') : 'Add site'],
]); ?>
<div class="topbar">
  <div>
    <h1><?= h($title) ?></h1>
    <p class="muted">Manual entry for the global country catalog (same fields as Bulk import). No project required.</p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_sites<?= $country !== '' ? '&sheet=' . urlencode($country) : '' ?>">Back</a>
</div>

<form class="card" method="post">
  <div class="form-grid">
    <div class="full">
      <label>Country sheet <span class="help">(required)</span></label>
      <select name="country" required>
        <option value="">— Select country —</option>
        <?php foreach ($countryGroups as $regionCode => $block): ?>
          <?php if (empty($block['countries'])) {
              continue;
          } ?>
          <optgroup label="<?= h($block['label']) ?>">
            <?php foreach ($block['countries'] as $c): ?>
              <option value="<?= h($c['name']) ?>" <?= $country === $c['name'] ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <p class="help">Grouped by Europe, North America, and English markets.</p>
    </div>
    <div><label>Domain <span class="help">(required)</span></label>
      <input name="domain" value="<?= h($site['domain'] ?? '') ?>" required placeholder="example.de"></div>
    <div><label>URL</label><input name="url" value="<?= h($site['url'] ?? '') ?>" placeholder="https://…"></div>
    <div><label>Language</label><input name="language" value="<?= h($site['language'] ?? '') ?>"></div>
    <div><label>Region</label>
      <select name="region">
        <option value="">—</option>
        <?php foreach (regions() as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($site['region'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Niche</label><input name="niche" value="<?= h($site['niche'] ?? '') ?>"></div>
    <div><label>DR</label><input name="dr" type="number" value="<?= h((string) ($site['dr'] ?? '')) ?>"></div>
    <div><label>DA</label><input name="da" type="number" value="<?= h((string) ($site['da'] ?? '')) ?>"></div>
    <div><label>Traffic</label><input name="traffic" type="number" value="<?= h((string) ($site['traffic'] ?? '')) ?>"></div>
    <div><label>Publisher quote</label><input name="publisher_quote_price" value="<?= h((string) ($site['publisher_quote_price'] ?? '')) ?>"></div>
    <div><label>Agreed / backlink price</label><input name="backlink_price" value="<?= h((string) ($site['backlink_price'] ?? '')) ?>"></div>
    <div><label>Currency</label><input name="currency" value="<?= h($site['currency'] ?? 'EUR') ?>"></div>
    <div><label>Site status</label>
      <select name="status">
        <?php foreach (site_statuses() as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= ($site['status'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Order status</label>
      <select name="order_status">
        <?php foreach (inventory_order_statuses() as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= ($site['order_status'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Client name</label><input name="client_name" value="<?= h($site['inventory_client_name'] ?? '') ?>"></div>
    <div><label>Our mailbox</label><input name="our_mailbox" value="<?= h($site['our_mailbox'] ?? '') ?>"></div>
    <div><label>Our contact name</label><input name="our_contact_name" value="<?= h($site['our_contact_name'] ?? '') ?>"></div>
    <div class="full"><label>Admin comments</label>
      <textarea name="admin_comments" rows="3"><?= h($site['admin_comments'] ?? '') ?></textarea></div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Add to country catalog' ?></button>
  </p>
</form>
<?php render_footer('admin'); ?>
