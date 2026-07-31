<?php
require_admin();
require_once __DIR__ . '/../../includes/orders.php';

$id = (int) get('id');
$client = get_client_or_404($id);
$orders = fetch_orders_query(['client_id' => $id]);

render_header($client['name'], 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= h($client['name']) ?></h1>
    <p class="muted">
      <a href="mailto:<?= h($client['email']) ?>"><?= h($client['email']) ?></a>
      · Project <a href="index.php?page=admin_project&id=<?= (int)$client['project_id'] ?>&tab=clients"><?= h($client['project_name']) ?></a>
    </p>
  </div>
  <div class="actions">
    <a class="btn" href="index.php?page=admin_order_form&client_id=<?= $id ?>">Add publication order</a>
    <a class="btn secondary" href="index.php?page=admin_client_form&id=<?= $id ?>">Edit client</a>
    <a class="btn secondary" href="index.php?page=admin_orders_export&client_id=<?= $id ?>&download=1">Download CSV</a>
  </div>
</div>

<?php if ($client['notes']): ?>
<div class="card"><p><?= nl2br(h($client['notes'])) ?></p></div>
<?php endif; ?>

<div class="card">
  <h2>Publication orders / deals</h2>
  <table>
    <thead>
      <tr>
        <th>Site</th>
        <th>Article URL</th>
        <th>Date sent</th>
        <th>Price</th>
        <th>Live URL</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= h($o['site_domain']) ?></td>
        <td><?php if ($o['article_url']): ?><a href="<?= h($o['article_url']) ?>" target="_blank">Article</a><?php else: ?>—<?php endif; ?></td>
        <td><?= h($o['sent_for_publication_at'] ?: '—') ?></td>
        <td><?= money_or_dash($o['client_price']) ?> <?= h($o['currency']) ?></td>
        <td><?php if ($o['live_url']): ?><a href="<?= h($o['live_url']) ?>" target="_blank">Live</a><?php else: ?><span class="muted">blank</span><?php endif; ?></td>
        <td><?= badge($o['status']) ?></td>
        <td><a class="btn small" href="index.php?page=admin_order_form&id=<?= (int)$o['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?>
      <tr><td colspan="7" class="muted">No orders yet. Add a publication order for this client.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer('admin'); ?>
