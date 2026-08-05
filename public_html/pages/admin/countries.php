<?php
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'save') {
        $id = (int) post('id');
        $region = (string) post('region');
        $code = strtoupper(trim((string) post('code')));
        $name = trim((string) post('name'));
        $lang = trim((string) post('default_language'));
        $active = post('is_active') ? 1 : 0;
        if ($name === '' || !isset(regions()[$region])) {
            flash('error', 'Name and valid region are required.');
        } else {
            try {
                if ($id) {
                    db()->prepare(
                        'UPDATE countries SET region=?, code=?, name=?, default_language=?, is_active=? WHERE id=?'
                    )->execute([$region, $code, $name, $lang, $active, $id]);
                } else {
                    db()->prepare(
                        'INSERT INTO countries (region, code, name, default_language, is_active) VALUES (?,?,?,?,?)'
                    )->execute([$region, $code, $name, $lang, $active]);
                }
                flash('ok', 'Country saved.');
            } catch (PDOException $e) {
                flash('error', 'Could not save country (duplicate name?).');
            }
        }
    }
    redirect('index.php?page=admin_countries');
}

$editId = (int) get('edit');
$edit = null;
if ($editId) {
    $s = db()->prepare('SELECT * FROM countries WHERE id=?');
    $s->execute([$editId]);
    $edit = $s->fetch();
}
$grouped = countries_grouped();
// also show inactive
$all = db()->query('SELECT * FROM countries ORDER BY region, name')->fetchAll();
$counts = [];
foreach (db()->query("SELECT country, COUNT(*) c FROM sites WHERE country <> '' GROUP BY country") as $row) {
    $counts[$row['country']] = (int) $row['c'];
}

render_header('Countries', 'admin');
?>
<div class="topbar">
  <div>
    <h1>Countries</h1>
    <p class="muted">Reference list for region → country → default language. Used in catalog and prospect filters.</p>
  </div>
</div>

<div class="grid" style="grid-template-columns:1.4fr 1fr">
<div>
<?php foreach (regions() as $regCode => $regLabel): ?>
  <div class="card">
    <h2><?= h($regLabel) ?></h2>
    <table>
      <thead><tr><th>Country</th><th>Code</th><th>Language</th><th>Sites</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($all as $c): if ($c['region'] !== $regCode) {
          continue;
      } ?>
        <tr>
          <td><?= h($c['name']) ?></td>
          <td><?= h($c['code']) ?></td>
          <td><?= h($c['default_language'] ?: '—') ?></td>
          <td><?= (int) ($counts[$c['name']] ?? 0) ?></td>
          <td><?= $c['is_active'] ? 'Yes' : 'No' ?></td>
          <td><a href="index.php?page=admin_countries&edit=<?= (int) $c['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Edit country' : 'Add country' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <label>Region</label>
    <select name="region" required>
      <?php foreach (regions() as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= ($edit['region'] ?? 'europe') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Country name</label>
    <input name="name" value="<?= h($edit['name'] ?? '') ?>" required>
    <label>Code</label>
    <input name="code" value="<?= h($edit['code'] ?? '') ?>" placeholder="DE">
    <label>Default language</label>
    <input name="default_language" value="<?= h($edit['default_language'] ?? '') ?>">
    <label style="font-weight:500;margin-top:0.8rem">
      <input type="checkbox" name="is_active" value="1" <?= !$edit || !empty($edit['is_active']) ? 'checked' : '' ?>> Active
    </label>
    <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save</button></p>
  </form>
</div>
</div>
<?php render_footer('admin'); ?>
