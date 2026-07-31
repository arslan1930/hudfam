<?php
$user = require_admin();
$projectId = (int) get('project_id');
$stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('index.php?page=admin_projects');
}

$agreed = db()->query("SELECT * FROM sites WHERE status='agreed' ORDER BY domain")->fetchAll();
$histStmt = db()->prepare(
    "SELECT pi.* FROM pitch_items pi
     JOIN pitches ph ON ph.id=pi.pitch_id
     WHERE ph.project_id=? AND pi.site_id=?
     ORDER BY pi.updated_at DESC LIMIT 3"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) post('title'));
    $notes = trim((string) post('notes'));
    $siteIds = array_map('intval', (array) ($_POST['sites'] ?? []));
    if ($title === '' || !$siteIds) {
        flash('error', 'Title and at least one agreed site are required.');
    } else {
        db()->prepare(
            "INSERT INTO pitches (project_id, title, status, notes, sent_at, created_by) VALUES (?,?, 'sent', ?, NOW(), ?)"
        )->execute([$projectId, $title, $notes, $user['id']]);
        $pitchId = (int) db()->lastInsertId();
        $ins = db()->prepare(
            "INSERT INTO pitch_items (pitch_id, site_id, offered_price, item_status, updated_by)
             VALUES (?,?,?, 'sent', ?)"
        );
        $upd = db()->prepare("UPDATE sites SET status='sent' WHERE id=?");
        foreach ($siteIds as $sid) {
            $site = db()->prepare('SELECT backlink_price FROM sites WHERE id=?');
            $site->execute([$sid]);
            $price = $site->fetchColumn();
            $ins->execute([$pitchId, $sid, $price, $user['id']]);
            $upd->execute([$sid]);
        }
        flash('ok', 'Pack sent with ' . count($siteIds) . ' site(s).');
        redirect('index.php?page=admin_project&id=' . $projectId . '&tab=sent');
    }
}

render_header('Send pack', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Send pack · <?= h($project['name']) ?></h1>
    <p class="muted">Past comments stay visible. You can re-offer sites later.</p>
  </div>
</div>
<div class="card">
<form method="post">
  <label>Pitch title</label>
  <input name="title" required placeholder="Pack #1">
  <label>Notes</label>
  <textarea name="notes" rows="2"></textarea>
  <label style="margin-top:1rem">Agreed sites</label>
  <div class="checkbox-list">
    <?php foreach ($agreed as $site):
      $histStmt->execute([$projectId, $site['id']]);
      $history = $histStmt->fetchAll();
    ?>
      <label>
        <input type="checkbox" name="sites[]" value="<?= (int)$site['id'] ?>">
        <span>
          <strong><?= h($site['domain']) ?></strong>
          · DR <?= h((string)($site['dr'] ?? '—')) ?> · <?= h($site['country'] ?: '—') ?>
          · <?= money_or_dash($site['backlink_price']) ?> <?= h($site['currency']) ?>
          <?php if ($site['warning_flags']): ?><br><span class="badge rejected"><?= h($site['warning_flags']) ?></span><?php endif; ?>
          <?php foreach ($history as $hrow): ?>
            <br><span class="muted"><?= h($hrow['item_status']) ?><?php if ($hrow['reject_reason_code']): ?> · <?= h(reject_reasons()[$hrow['reject_reason_code']] ?? $hrow['reject_reason_code']) ?><?php endif; ?><?php if ($hrow['reject_comment']): ?> — <?= h($hrow['reject_comment']) ?><?php endif; ?></span>
          <?php endforeach; ?>
        </span>
      </label>
    <?php endforeach; ?>
    <?php if (!$agreed): ?><p class="muted">No agreed sites in the pool.</p><?php endif; ?>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Send pack</button>
    <a class="btn secondary" href="index.php?page=admin_project&id=<?= $projectId ?>">Cancel</a>
  </p>
</form>
</div>
<?php render_footer('admin'); ?>
