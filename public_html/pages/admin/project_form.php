<?php
$user = require_admin();
$id = (int) get('id');
$project = [
    'name' => '', 'client_name' => '', 'contact_email' => '', 'status' => 'active',
    'niche' => '', 'countries' => '', 'region_focus' => '', 'budget' => '',
    'price_min' => '', 'price_max' => '', 'currency' => 'EUR',
    'min_dr' => '', 'min_da' => '', 'min_traffic' => '',
    'avoid_notes' => '', 'workflow_notes' => '', 'requirements_brief' => '',
];
$memberIds = [];
$adminIds = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
    $stmt->execute([$id]);
    $project = $stmt->fetch() ?: $project;
    $memberIds = db()->prepare('SELECT user_id FROM project_members WHERE project_id=?');
    $memberIds->execute([$id]);
    $memberIds = array_map('intval', array_column($memberIds->fetchAll(), 'user_id'));
    $adminIds = project_admin_ids($id);
}
$teamUsers = db()->query("SELECT id, username, full_name FROM users WHERE role='team' AND is_active=1 ORDER BY username")->fetchAll();
$adminUsers = list_admin_users(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'name', 'client_name', 'contact_email', 'status', 'niche', 'countries', 'region_focus',
        'budget', 'currency', 'avoid_notes', 'workflow_notes', 'requirements_brief',
    ];
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim((string) post($f));
    }
    foreach (['price_min', 'price_max', 'min_dr', 'min_da', 'min_traffic'] as $f) {
        $v = trim((string) post($f));
        $data[$f] = $v === '' ? null : $v;
    }
    $members = array_map('intval', (array) ($_POST['members'] ?? []));
    $admins = array_map('intval', (array) ($_POST['admins'] ?? []));
    // Always include the saving admin as collaborator
    if (!in_array((int) $user['id'], $admins, true)) {
        $admins[] = (int) $user['id'];
    }
    if ($data['name'] === '') {
        flash('error', 'Project name is required.');
    } else {
        if ($id) {
            $sql = 'UPDATE projects SET name=?, client_name=?, contact_email=?, status=?, niche=?, countries=?, region_focus=?, budget=?, price_min=?, price_max=?, currency=?, min_dr=?, min_da=?, min_traffic=?, avoid_notes=?, workflow_notes=?, requirements_brief=? WHERE id=?';
            db()->prepare($sql)->execute([
                $data['name'], $data['client_name'], $data['contact_email'], $data['status'], $data['niche'],
                $data['countries'], $data['region_focus'], $data['budget'], $data['price_min'], $data['price_max'],
                $data['currency'], $data['min_dr'], $data['min_da'], $data['min_traffic'], $data['avoid_notes'],
                $data['workflow_notes'], $data['requirements_brief'], $id,
            ]);
        } else {
            $sql = 'INSERT INTO projects (name, client_name, contact_email, status, niche, countries, region_focus, budget, price_min, price_max, currency, min_dr, min_da, min_traffic, avoid_notes, workflow_notes, requirements_brief, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            db()->prepare($sql)->execute([
                $data['name'], $data['client_name'], $data['contact_email'], $data['status'], $data['niche'],
                $data['countries'], $data['region_focus'], $data['budget'], $data['price_min'], $data['price_max'],
                $data['currency'], $data['min_dr'], $data['min_da'], $data['min_traffic'], $data['avoid_notes'],
                $data['workflow_notes'], $data['requirements_brief'], $user['id'],
            ]);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM project_members WHERE project_id=?')->execute([$id]);
        $ins = db()->prepare('INSERT INTO project_members (project_id, user_id) VALUES (?,?)');
        foreach ($members as $uid) {
            $ins->execute([$id, $uid]);
        }
        sync_project_admins($id, $admins);
        flash('ok', 'Project saved. Collaborating admins and team updated.');
        redirect('index.php?page=admin_project&id=' . $id . '&tab=inventory');
    }
}

render_header($id ? 'Edit project' : 'New project', 'admin');
?>
<div class="topbar"><div><h1><?= $id ? 'Edit project' : 'New project' ?></h1></div></div>
<div class="card">
<form method="post">
  <div class="form-grid">
    <div><label>Folder name</label><input name="name" value="<?= h($project['name']) ?>" required placeholder="rexbo.de"></div>
    <div><label>Client name</label><input name="client_name" value="<?= h($project['client_name']) ?>"></div>
    <div><label>Contact email</label><input name="contact_email" value="<?= h($project['contact_email']) ?>"></div>
    <div><label>Status</label>
      <select name="status">
        <?php foreach (['active','paused','archived'] as $st): ?>
          <option value="<?= $st ?>" <?= $project['status']===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Niche</label><input name="niche" value="<?= h($project['niche']) ?>"></div>
    <div><label>Countries</label><input name="countries" value="<?= h($project['countries']) ?>"></div>
    <div><label>Region focus</label><input name="region_focus" value="<?= h($project['region_focus']) ?>"></div>
    <div><label>Budget</label><input name="budget" value="<?= h($project['budget']) ?>"></div>
    <div><label>Price min</label><input name="price_min" value="<?= h((string) $project['price_min']) ?>"></div>
    <div><label>Price max</label><input name="price_max" value="<?= h((string) $project['price_max']) ?>"></div>
    <div><label>Currency</label><input name="currency" value="<?= h($project['currency']) ?>"></div>
    <div><label>Min DR</label><input name="min_dr" value="<?= h((string) $project['min_dr']) ?>"></div>
    <div><label>Min DA</label><input name="min_da" value="<?= h((string) $project['min_da']) ?>"></div>
    <div><label>Min traffic</label><input name="min_traffic" value="<?= h((string) $project['min_traffic']) ?>"></div>
    <div class="full"><label>Avoid</label><textarea name="avoid_notes" rows="2"><?= h($project['avoid_notes']) ?></textarea></div>
    <div class="full"><label>Workflow notes</label><textarea name="workflow_notes" rows="2"><?= h($project['workflow_notes']) ?></textarea></div>
    <div class="full"><label>Requirements brief</label><textarea name="requirements_brief" rows="3"><?= h($project['requirements_brief']) ?></textarea></div>
    <div class="full"><label>Collaborating admins</label>
      <p class="help">Multiple admins can work on this project. Each has unique name + contact details under Users.</p>
      <?php foreach ($adminUsers as $au): ?>
        <label style="font-weight:500;display:block">
          <input type="checkbox" name="admins[]" value="<?= (int) $au['id'] ?>"
            <?= in_array((int) $au['id'], $adminIds, true) || (!$id && (int) $au['id'] === (int) $user['id']) ? 'checked' : '' ?>>
          <?= h($au['full_name'] ?: $au['username']) ?>
          <span class="muted">· <?= h($au['email'] ?: 'no email') ?><?= $au['phone'] !== '' ? ' · ' . h($au['phone']) : '' ?></span>
        </label>
      <?php endforeach; ?>
      <?php if (!$adminUsers): ?><p class="muted">Create admin users first.</p><?php endif; ?>
    </div>
    <div class="full"><label>Assigned team</label>
      <?php foreach ($teamUsers as $tu): ?>
        <label style="font-weight:500"><input type="checkbox" name="members[]" value="<?= (int) $tu['id'] ?>" <?= in_array((int)$tu['id'], $memberIds, true)?'checked':'' ?>> <?= h($tu['full_name'] ?: $tu['username']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Save project</button>
    <a class="btn secondary" href="index.php?page=admin_projects">Cancel</a>
  </p>
</form>
</div>
<?php render_footer('admin'); ?>
