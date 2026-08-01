<?php
$user = require_admin();
require_once __DIR__ . '/../../includes/orders.php';

$id = (int) get('id');
$clientId = (int) get('client_id');
$order = [
    'client_id' => $clientId,
    'site_id' => '',
    'site_domain' => '',
    'article_url' => '',
    'sent_for_publication_at' => date('Y-m-d'),
    'client_price' => '',
    'currency' => 'EUR',
    'live_url' => '',
    'status' => 'processing',
    'admin_notes' => '',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM publication_orders WHERE id=?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('error', 'Order not found.');
        redirect('index.php?page=admin_clients');
    }
    $order = $found;
    $clientId = (int) $order['client_id'];
}

if (!$clientId) {
    flash('error', 'Select a client folder first.');
    redirect('index.php?page=admin_clients');
}
$client = get_client_or_404($clientId);
if (!$id) {
    $order['currency'] = $client['project_currency'] ?: 'EUR';
}

$sites = db()->prepare(
    'SELECT id, domain, backlink_price, status, our_mailbox FROM sites
     WHERE primary_project_id = ?
     ORDER BY domain LIMIT 500'
);
$sites->execute([(int) $client['project_id']]);
$sites = $sites->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteId = post('site_id') === '' ? null : (int) post('site_id');
    $domain = strtolower(trim((string) post('site_domain')));
    if ($siteId) {
        $s = db()->prepare('SELECT domain FROM sites WHERE id=?');
        $s->execute([$siteId]);
        $d = $s->fetchColumn();
        if ($d) {
            $domain = $d;
        }
    }
    $article = trim((string) post('article_url'));
    $sentDate = trim((string) post('sent_for_publication_at'));
    $price = trim((string) post('client_price'));
    $price = $price === '' ? null : $price;
    $currency = trim((string) post('currency')) ?: 'EUR';
    $live = trim((string) post('live_url'));
    $status = post('status') === 'completed' ? 'completed' : 'processing';
    $notes = trim((string) post('admin_notes'));
    $markComplete = post('action') === 'complete';

    if ($markComplete) {
        $status = 'completed';
    }
    if ($domain === '') {
        flash('error', 'Site domain is required.');
    } elseif ($status === 'completed' && $live === '') {
        flash('error', 'Live URL is required to mark as completed.');
    } else {
        $completedAt = null;
        if ($status === 'completed') {
            $completedAt = $order['completed_at'] ?: date('Y-m-d H:i:s');
        }
        if ($id) {
            db()->prepare(
                'UPDATE publication_orders SET site_id=?, site_domain=?, article_url=?, sent_for_publication_at=?,
                 client_price=?, currency=?, live_url=?, status=?, admin_notes=?, completed_at=? WHERE id=?'
            )->execute([
                $siteId, $domain, $article, $sentDate ?: null, $price, $currency, $live,
                $status, $notes, $completedAt, $id,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO publication_orders
                (client_id, site_id, site_domain, article_url, sent_for_publication_at, client_price, currency, live_url, status, admin_notes, completed_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $clientId, $siteId, $domain, $article, $sentDate ?: null, $price, $currency, $live,
                $status, $notes, $completedAt, $user['id'],
            ]);
            $id = (int) db()->lastInsertId();
        }
        flash('ok', $status === 'completed' ? 'Order marked completed.' : 'Order saved.');
        redirect('index.php?page=admin_client&id=' . $clientId);
    }
}

render_header($id ? 'Edit order' : 'New publication order', 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= $id ? 'Edit publication order' : 'New publication order' ?></h1>
    <p class="muted">Client: <?= h($client['name']) ?> &lt;<?= h($client['email']) ?>&gt; · <?= h($client['project_name']) ?></p>
  </div>
</div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div>
      <label>Pick site from inventory</label>
      <select name="site_id" id="site_id">
        <option value="">— type domain below —</option>
        <?php foreach ($sites as $s): ?>
          <option value="<?= (int)$s['id'] ?>"
            data-domain="<?= h($s['domain']) ?>"
            data-price="<?= h((string)$s['backlink_price']) ?>"
            <?= (string)$order['site_id']===(string)$s['id']?'selected':'' ?>>
            <?= h($s['domain']) ?> (<?= h($s['status']) ?><?= $s['backlink_price'] !== null ? ', '.$s['backlink_price'] : '' ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Site domain</label><input name="site_domain" id="site_domain" value="<?= h($order['site_domain']) ?>" required></div>
    <div class="full"><label>Article URL</label><input name="article_url" value="<?= h($order['article_url']) ?>" placeholder="URL of article sent for publication"></div>
    <div><label>Date sent for publication</label><input type="date" name="sent_for_publication_at" value="<?= h((string)$order['sent_for_publication_at']) ?>"></div>
    <div><label>Price client decided to pay</label><input name="client_price" value="<?= h((string)$order['client_price']) ?>"></div>
    <div><label>Currency</label><input name="currency" value="<?= h($order['currency']) ?>"></div>
    <div><label>Status</label>
      <select name="status">
        <?php foreach (publication_order_statuses() as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= $order['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="full"><label>Live URL <span class="muted">(blank until published)</span></label><input name="live_url" value="<?= h($order['live_url']) ?>" placeholder="Paste live published URL here"></div>
    <div class="full"><label>Admin notes</label><textarea name="admin_notes" rows="3"><?= h($order['admin_notes']) ?></textarea></div>
  </div>
  <p class="help">To complete an order, fill Live URL then click “Mark completed” (or set status to completed).</p>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit" name="action" value="save">Save order</button>
    <button class="btn secondary" type="submit" name="action" value="complete">Mark completed</button>
    <a class="btn secondary" href="index.php?page=admin_client&id=<?= $clientId ?>">Cancel</a>
  </p>
</form>
</div>
<script>
(function(){
  var sel = document.getElementById('site_id');
  var domain = document.getElementById('site_domain');
  if (!sel || !domain) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.domain) {
      domain.value = opt.dataset.domain;
      var price = document.querySelector('input[name=client_price]');
      if (price && opt.dataset.price && !price.value) price.value = opt.dataset.price;
    }
  });
})();
</script>
<?php render_footer('admin'); ?>
