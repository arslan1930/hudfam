<?php
$user = require_team();
ensure_email_campaign_schema();

$sq = trim((string) get('sq'));
$country = trim((string) (post('country') ?: get('country')));
$paste = '';
$quickResult = null;
$lookup = null;
$countryGroups = email_campaign_countries_grouped_for_select();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    if ($action === 'quick_cut') {
        $paste = (string) post('emails');
        $country = trim((string) post('country'));
        $status = (string) post('status');
        if (!in_array($status, ['replied', 'dealing', 'do_not_email'], true)) {
            $status = 'replied';
        }
        if ($country === '') {
            flash('error', 'Select a country sheet first (Europe, North America, or English markets).');
        } else {
            $quickResult = email_campaign_quick_cut(
                $paste,
                $status,
                $user,
                trim((string) post('notes')),
                $country
            );
            $parts = [];
            if ($quickResult['cut'] > 0) {
                $parts[] = "Cut {$quickResult['cut']} from {$country} Ready send list ("
                    . (email_campaign_statuses()[$status] ?? $status) . ').';
            }
            if ($quickResult['already'] > 0) {
                $parts[] = "{$quickResult['already']} already cut earlier in {$country}.";
            }
            if ($quickResult['missing']) {
                $parts[] = count($quickResult['missing'])
                    . ' email(s) not found in the ' . $country . ' sheet.';
            }
            if (!$parts) {
                flash('error', 'Paste at least one valid email address.');
            } else {
                flash($quickResult['cut'] > 0 ? 'ok' : 'error', implode(' ', $parts));
            }
        }
    } elseif ($action === 'set_status') {
        $id = (int) post('id');
        $st = (string) post('status');
        $country = trim((string) post('country'));
        if (!in_array($st, ['replied', 'dealing', 'do_not_email'], true)) {
            flash('error', 'Invalid status.');
        } elseif ($country === '') {
            flash('error', 'Select a country sheet before cutting.');
        } else {
            $row = db()->prepare('SELECT * FROM email_campaign_contacts WHERE id=? AND TRIM(country)=?');
            $row->execute([$id, $country]);
            $found = $row->fetch();
            if (!$found) {
                flash('error', 'Contact not found in the selected country sheet.');
            } else {
                email_campaign_set_status($id, $st, $user, trim((string) post('notes')));
                flash('ok', 'Marked — cut from ' . $country . ' send list.');
            }
        }
        redirect(
            'index.php?page=team_email_search&country=' . urlencode($country)
            . '&sq=' . urlencode((string) post('sq'))
        );
    }
}

if ($sq !== '' && $quickResult === null && $country !== '') {
    $lookup = lookup_email_campaign($sq, $country);
}

render_header('Cut replied emails', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Email campaigns', 'href' => 'index.php?page=team_email_campaigns'],
    ['label' => 'Quick cut'],
]); ?>
<div class="topbar">
  <div>
    <h1>Paste email → cut from send list</h1>
    <p class="muted">
      Choose the <strong>country sheet</strong> first (Europe / North America / English markets),
      then paste emails that replied or you are dealing with.
      Matches are confirmed only inside that country and removed from its Ready list (record stays).
    </p>
  </div>
  <a class="btn secondary" href="index.php?page=team_email_campaigns">Country sheets</a>
</div>
<?= guide_team_email_cut() ?>

<form class="card" method="post">
  <input type="hidden" name="action" value="quick_cut">

  <label for="country">Country sheet <span class="help">(required)</span></label>
  <select id="country" name="country" required>
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
  <p class="help">Countries are grouped by Europe, North America, and English markets.</p>

  <label for="emails" style="margin-top:0.9rem">Email address(es)</label>
  <textarea id="emails" name="emails" rows="5" required
    placeholder="editor@site.com&#10;hello@other.de"><?= h($paste) ?></textarea>
  <p class="help">One email per line (or comma/space separated). No URL needed.</p>
  <div class="form-grid" style="margin-top:0.7rem">
    <div>
      <label>Mark as</label>
      <select name="status">
        <option value="replied" selected>Replied</option>
        <option value="dealing">Dealing</option>
        <option value="do_not_email">Do not email</option>
      </select>
    </div>
    <div class="full">
      <label>Note (optional)</label>
      <input name="notes" placeholder="e.g. asked for price">
    </div>
  </div>
  <p class="actions" style="margin-top:1rem">
    <button class="btn" type="submit">Confirm &amp; cut from this country</button>
  </p>
</form>

<?php if ($quickResult && ($quickResult['rows'] || $quickResult['missing'])): ?>
<div class="card">
  <h2>Confirmed · <?= h($quickResult['country'] ?: $country) ?></h2>
  <?php if ($quickResult['rows']): ?>
  <table>
    <thead><tr><th>Email</th><th>URL</th><th>Country</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($quickResult['rows'] as $r): ?>
      <tr>
        <td><strong><?= h($r['email']) ?></strong></td>
        <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
        <td><?= h($r['country'] ?: '—') ?></td>
        <td>
          <span class="badge rejected"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span>
          <div class="help"><?= h(email_campaign_status_comment($r['status'])) ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($quickResult['missing']): ?>
    <p class="help" style="margin-top:0.8rem">
      Not found in <strong><?= h($quickResult['country'] ?: $country) ?></strong>
      (wrong country or spelling):
      <strong><?= h(implode(', ', $quickResult['missing'])) ?></strong>
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<details class="card" <?= $sq !== '' ? 'open' : '' ?>>
  <summary><strong>Or search by email / website in a country</strong></summary>
  <form class="super-search" method="get" style="margin-top:0.8rem">
    <input type="hidden" name="page" value="team_email_search">
    <label for="lookup_country">Country sheet <span class="help">(required)</span></label>
    <select id="lookup_country" name="country" required>
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
    <label for="sq" style="margin-top:0.7rem">Lookup</label>
    <div class="super-search-row">
      <input id="sq" name="sq" value="<?= h($sq) ?>" placeholder="editor@site.com or site.com" required>
      <button class="btn secondary" type="submit">Search</button>
    </div>
  </form>
  <?php if ($lookup !== null): ?>
  <div style="margin-top:1rem">
    <h2>Results · <?= h($country) ?> · “<?= h($sq) ?>”</h2>
    <?php if (!$lookup['matches']): ?>
      <p class="muted">Not in the <?= h($country) ?> email campaign sheet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>URL</th><th>Email</th><th>Country</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lookup['matches'] as $r): ?>
        <tr>
          <td><?= h($r['domain'] ?: $r['url'] ?: '—') ?></td>
          <td><strong><?= h($r['email']) ?></strong></td>
          <td><?= h($r['country'] ?: '—') ?></td>
          <td><span class="badge"><?= h(email_campaign_statuses()[$r['status']] ?? $r['status']) ?></span></td>
          <td>
            <?php if (!in_array($r['status'], email_campaign_cut_statuses(), true)): ?>
              <form method="post" class="actions">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="country" value="<?= h($country) ?>">
                <input type="hidden" name="sq" value="<?= h($sq) ?>">
                <select name="status" style="width:auto">
                  <option value="replied">Replied</option>
                  <option value="dealing">Dealing</option>
                  <option value="do_not_email">Do not email</option>
                </select>
                <button class="btn small" type="submit">Cut</button>
              </form>
            <?php else: ?>
              <span class="help">Already cut</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</details>
<?php render_footer('team'); ?>
