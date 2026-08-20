<?php
$user = require_admin();

$listUrl = 'index.php?page=admin_prospect_batches';
$filterUser = (int) (get('user') ?: 0);
$schemaOk = true;
$schemaError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'delete_day') {
        $id = (int) post('id');
        $alsoDb = (string) post('also_remove_db') === '1';
        $batch = get_prospect_batch($id);
        $result = delete_prospect_batch($id, $alsoDb);
        if (empty($result['ok'])) {
            flash('error', (string) ($result['error'] ?? 'Could not delete.'));
        } else {
            $msg = 'Deleted history day ' . (string) ($result['batch_date'] ?? '')
                . ' (' . (int) ($result['cleared'] ?? 0) . ' site(s)).';
            if ((int) ($result['db_removed'] ?? 0) > 0) {
                $msg .= ' Also removed ' . (int) $result['db_removed'] . ' from Our database.';
            }
            flash('ok', $msg);
        }
        $redir = $listUrl;
        $uid = (int) ($batch['user_id'] ?? $filterUser);
        if ($uid > 0) {
            $redir .= '&user=' . $uid;
        }
        redirect($redir);
    }
    flash('error', 'Unknown action.');
    redirect($listUrl);
}

$batches = [];
try {
    $batches = list_prospect_batches($filterUser > 0 ? $filterUser : null, 200);
} catch (Throwable $e) {
    $schemaOk = false;
    $schemaError = $e->getMessage();
}

$filterName = '';
if ($filterUser > 0) {
    foreach ($batches as $b) {
        if ((int) $b['user_id'] === $filterUser) {
            $filterName = (string) ($b['full_name'] ?: $b['username']);
            break;
        }
    }
    if ($filterName === '') {
        try {
            $u = db()->prepare('SELECT full_name, username FROM users WHERE id=? LIMIT 1');
            $u->execute([$filterUser]);
            $row = $u->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $filterName = (string) ($row['full_name'] ?: $row['username']);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

render_header('Site adding history', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Site adding history'],
]); ?>
<div class="topbar">
  <div>
    <h1>Site adding history</h1>
    <p class="muted">
      Who added how many sites each day.
      <?php if ($filterName !== ''): ?>
        · showing <strong><?= h($filterName) ?></strong>
        · <a href="<?= h($listUrl) ?>">Show all</a>
      <?php else: ?>
        Open a day to edit, copy/cut, or delete.
      <?php endif; ?>
    </p>
  </div>
  <a class="btn secondary" href="index.php?page=admin_prospects">Our database</a>
</div>
<?= guide_add_history() ?>

<?php if (!$schemaOk): ?>
<ul class="messages"><li class="error">
  Prospect history tables are missing or broken<?= $schemaError !== '' ? ': ' . h($schemaError) : '.' ?>
  Open <a href="upgrade.php">upgrade.php</a> once, then reload.
</li></ul>
<?php endif; ?>

<div class="card">
  <?php if ($batches): ?>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Person</th>
        <th>Sites</th>
        <th>Country</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><strong><?= h($b['batch_date']) ?></strong></td>
        <td><?= h($b['full_name'] ?: $b['username']) ?></td>
        <td><span class="badge agreed"><?= (int) $b['site_count'] ?></span></td>
        <td><?= h($b['country'] ?: '—') ?></td>
        <td class="actions">
          <a class="btn small" href="index.php?page=admin_prospect_batch&amp;id=<?= (int) $b['id'] ?>">Edit</a>
          <form method="post" action="<?= h($listUrl) ?><?= $filterUser > 0 ? '&amp;user=' . $filterUser : '' ?>"
                style="display:inline"
                onsubmit="return confirm('Delete history day <?= h($b['batch_date']) ?> for <?= h($b['full_name'] ?: $b['username']) ?>?');">
            <input type="hidden" name="action" value="delete_day">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <button class="btn danger small" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <p><?= $filterUser > 0 ? 'No history days for this teammate.' : 'No adds yet.' ?></p>
  </div>
  <?php endif; ?>
</div>
<?php render_footer('admin'); ?>
