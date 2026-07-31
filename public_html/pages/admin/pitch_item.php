<?php
$user = require_admin();
$id = (int) get('id');
$stmt = db()->prepare(
    "SELECT pi.*, s.domain, ph.project_id, ph.title pitch_title, p.name project_name
     FROM pitch_items pi
     JOIN sites s ON s.id=pi.site_id
     JOIN pitches ph ON ph.id=pi.pitch_id
     JOIN projects p ON p.id=ph.project_id
     WHERE pi.id=?"
);
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
    flash('error', 'Item not found.');
    redirect('index.php?page=admin_projects');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = (string) post('item_status');
    $reason = (string) post('reject_reason_code');
    $rejectComment = trim((string) post('reject_comment'));
    $clientNotes = trim((string) post('client_notes'));
    $liveLink = trim((string) post('live_link'));
    $price = trim((string) post('offered_price'));
    $price = $price === '' ? null : $price;

    if ($status === 'rejected' && $reason === '') {
        flash('error', 'Pick a rejection reason.');
    } elseif ($status === 'completed' && $liveLink === '') {
        flash('error', 'Live link is required when completing.');
    } else {
        db()->prepare(
            'UPDATE pitch_items SET item_status=?, reject_reason_code=?, reject_comment=?, client_notes=?, live_link=?, offered_price=?, updated_by=? WHERE id=?'
        )->execute([$status, $reason, $rejectComment, $clientNotes, $liveLink, $price, $user['id'], $id]);

        $siteStatus = $status;
        $flagsSql = '';
        $flagParams = [];
        if ($status === 'rejected' && $reason) {
            $label = reject_reasons()[$reason] ?? $reason;
            $site = db()->prepare('SELECT warning_flags FROM sites WHERE id=?');
            $site->execute([$item['site_id']]);
            $flags = $site->fetchColumn() ?: '';
            $parts = array_filter(array_map('trim', explode(',', $flags)));
            if (!in_array($label, $parts, true)) {
                $parts[] = $label;
            }
            $flagsSql = ', warning_flags=?';
            $flagParams[] = implode(', ', $parts);
        }
        $sql = "UPDATE sites SET status=?{$flagsSql} WHERE id=?";
        db()->prepare($sql)->execute(array_merge([$siteStatus], $flagParams, [$item['site_id']]));

        if ($status === 'completed') {
            $exists = db()->prepare('SELECT id FROM published_placements WHERE pitch_item_id=?');
            $exists->execute([$id]);
            $pubId = $exists->fetchColumn();
            if ($pubId) {
                db()->prepare(
                    'UPDATE published_placements SET live_link=?, notes=?, created_by=? WHERE id=?'
                )->execute([$liveLink, $clientNotes, $user['id'], $pubId]);
            } else {
                db()->prepare(
                    'INSERT INTO published_placements (project_id, site_id, pitch_item_id, live_link, notes, created_by)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$item['project_id'], $item['site_id'], $id, $liveLink, $clientNotes, $user['id']]);
            }
        }
        flash('ok', 'Updated ' . $item['domain']);
        redirect('index.php?page=admin_project&id=' . $item['project_id'] . '&tab=' . $status);
    }
}

render_header('Update site status', 'admin');
?>
<div class="topbar">
  <div>
    <h1><?= h($item['domain']) ?></h1>
    <p class="muted"><?= h($item['project_name']) ?> · <?= h($item['pitch_title']) ?></p>
  </div>
</div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div>
      <label>Status</label>
      <select name="item_status">
        <?php foreach (['sent','rejected','processing','completed'] as $st): ?>
          <option value="<?= $st ?>" <?= $item['item_status']===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Reject reason</label>
      <select name="reject_reason_code">
        <option value="">—</option>
        <?php foreach (reject_reasons() as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= $item['reject_reason_code']===$code?'selected':'' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Offered price</label><input name="offered_price" value="<?= h((string)$item['offered_price']) ?>"></div>
    <div><label>Live link</label><input name="live_link" value="<?= h($item['live_link']) ?>"></div>
    <div class="full"><label>Reject comment</label><textarea name="reject_comment" rows="2"><?= h($item['reject_comment']) ?></textarea></div>
    <div class="full"><label>Client notes</label><textarea name="client_notes" rows="2"><?= h($item['client_notes']) ?></textarea></div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save status</button>
    <a class="btn secondary" href="index.php?page=admin_project&id=<?= (int)$item['project_id'] ?>">Back</a>
  </p>
</form>
</div>
<?php render_footer('admin'); ?>
