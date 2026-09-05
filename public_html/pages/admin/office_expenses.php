<?php
$user = require_admin();
ensure_office_expense_schema();

$actorId = (int) ($user['id'] ?? 0);
$ym = office_expense_normalize_year_month((string) get('month'));
$historyAdmin = max(0, (int) get('history_admin'));
$editId = max(0, (int) get('edit'));

$month = office_expense_get_or_create_month($ym);
$monthId = (int) $month['id'];
$open = office_expense_month_is_open($month);
$pageUrl = office_expense_page_url($ym, $historyAdmin > 0 ? ['history_admin' => $historyAdmin] : []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    try {
        if ($action === 'add') {
            office_expense_add_row($monthId, [
                'paid_on' => (string) post('paid_on'),
                'category' => (string) post('category'),
                'description' => (string) post('description'),
                'amount' => post('amount'),
                'currency' => (string) post('currency'),
                'paid_by' => (int) post('paid_by'),
                'note' => (string) post('note'),
            ], $actorId);
            flash('ok', 'Payment added.');
            redirect($pageUrl);
        }
        if ($action === 'update') {
            $rowId = (int) post('id');
            office_expense_update_row($rowId, [
                'paid_on' => (string) post('paid_on'),
                'category' => (string) post('category'),
                'description' => (string) post('description'),
                'amount' => post('amount'),
                'currency' => (string) post('currency'),
                'paid_by' => (int) post('paid_by'),
                'note' => (string) post('note'),
            ], $actorId);
            flash('ok', 'Payment updated.');
            redirect($pageUrl . '#row-' . $rowId);
        }
        if ($action === 'delete') {
            office_expense_delete_row((int) post('id'), $actorId);
            flash('ok', 'Payment deleted.');
            redirect($pageUrl);
        }
        if ($action === 'save_month') {
            office_expense_save_month($monthId, $actorId);
            flash('ok', 'Saved ' . office_expense_month_label($ym) . '. Payments are locked until you reopen.');
            redirect($pageUrl);
        }
        if ($action === 'reopen_month') {
            office_expense_reopen_month($monthId, $actorId);
            flash('ok', 'Reopened ' . office_expense_month_label($ym) . '. Admins can edit payments again.');
            redirect($pageUrl);
        }
        flash('error', 'Unknown action.');
        redirect($pageUrl);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($pageUrl);
    }
}

$month = office_expense_get_month($monthId) ?: $month;
$open = office_expense_month_is_open($month);
$rows = office_expense_list_rows($monthId);
$totals = office_expense_totals($monthId);
$events = office_expense_list_events($monthId, $historyAdmin);
$admins = function_exists('order_admin_options') ? order_admin_options() : list_admin_users(true);
$editRow = null;
if ($open && $editId > 0) {
    foreach ($rows as $r) {
        if ((int) ($r['id'] ?? 0) === $editId) {
            $editRow = $r;
            break;
        }
    }
}

$formPaidOn = $editRow
    ? (string) ($editRow['paid_on'] ?? office_expense_default_paid_on($ym))
    : office_expense_default_paid_on($ym);
$formCategory = $editRow
    ? office_expense_normalize_category((string) ($editRow['category'] ?? 'other'))
    : 'salary';
$formDescription = $editRow ? (string) ($editRow['description'] ?? '') : '';
$formAmount = $editRow ? format_money($editRow['amount'] ?? 0) : '';
$formCurrency = $editRow
    ? office_expense_normalize_currency($editRow['currency'] ?? 'eur')
    : 'eur';
$formPaidBy = $editRow ? (int) ($editRow['paid_by'] ?? $actorId) : $actorId;
$formNote = $editRow ? (string) ($editRow['note'] ?? '') : '';

$prevYm = office_expense_shift_month($ym, -1);
$nextYm = office_expense_shift_month($ym, 1);
$savedBy = office_expense_person_name($month['saved_by_full_name'] ?? null, $month['saved_by_username'] ?? null);
$savedAt = trim((string) ($month['saved_at'] ?? ''));

render_header('Office expenses', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Office expenses'],
]); ?>

<div class="topbar">
  <div>
    <h1><?= label_with_info('Office expenses', 'Shared Admin ledger. Each calendar month is one office bill. Each payment is Euro or PKR. Save this month to freeze it; Reopen to edit again.') ?></h1>
    <p class="muted">Salaries, rent, grocery, internet, and other bills in Euro or PKR. All Admins can add and edit. Team cannot open this page.</p>
  </div>
  <div class="actions office-expense-month-nav">
    <a class="btn secondary small" href="<?= h(office_expense_page_url($prevYm, $historyAdmin > 0 ? ['history_admin' => $historyAdmin] : [])) ?>"><?= h(office_expense_month_label($prevYm)) ?></a>
    <form method="get" action="index.php" class="office-expense-month-jump" data-no-draft>
      <input type="hidden" name="page" value="admin_office_expenses">
      <?php if ($historyAdmin > 0): ?>
        <input type="hidden" name="history_admin" value="<?= (int) $historyAdmin ?>">
      <?php endif; ?>
      <label class="visually-hidden" for="office-expense-month">Month</label>
      <select id="office-expense-month" name="month" onchange="this.form.submit()" aria-label="Open another month">
        <?php foreach (office_expense_month_jumper_options($ym) as $opt): ?>
          <option value="<?= h($opt['value']) ?>"<?= $opt['value'] === $ym ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn small" type="submit">Open</button></noscript>
    </form>
    <a class="btn secondary small" href="<?= h(office_expense_page_url($nextYm, $historyAdmin > 0 ? ['history_admin' => $historyAdmin] : [])) ?>"><?= h(office_expense_month_label($nextYm)) ?></a>
    <?php if ($open): ?>
      <form method="post" action="<?= h($pageUrl) ?>" class="inline"
            onsubmit="return confirm('Save <?= h(office_expense_month_label($ym)) ?>? Payments cannot be edited until you reopen.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_month">
        <button class="btn" type="submit">Save this month</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= h($pageUrl) ?>" class="inline"
            onsubmit="return confirm('Reopen <?= h(office_expense_month_label($ym)) ?> so Admins can edit payments again?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reopen_month">
        <button class="btn secondary" type="submit">Reopen month</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?= guide_office_expenses() ?>

<p class="office-expense-status-line">
  <?php if ($open): ?>
    <span class="office-expense-status is-open">Open</span>
    <?= h(office_expense_month_label($ym)) ?> — add and edit payments, then save this month.
  <?php else: ?>
    <span class="office-expense-status is-saved">Saved</span>
    <?= h(office_expense_month_label($ym)) ?>
    <?php if ($savedAt !== ''): ?>
      · <?= h($savedAt) ?><?= $savedBy !== 'Unknown' ? ' · ' . h($savedBy) : '' ?>
    <?php endif; ?>
    — view only until you reopen.
  <?php endif; ?>
</p>

<?php if ($open): ?>
<section class="card" id="office-expense-add">
  <div class="invoice-list-toolbar">
    <h2 style="margin:0"><?= $editRow ? 'Edit payment' : 'Add payment' ?></h2>
    <?php if ($editRow): ?>
      <a class="btn secondary small" href="<?= h($pageUrl) ?>#office-expense-add">Cancel edit</a>
    <?php endif; ?>
  </div>
  <form method="post" action="<?= h($pageUrl) ?>" class="office-expense-add">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'add' ?>">
    <?php if ($editRow): ?>
      <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <?php endif; ?>
    <div class="grid office-expense-add-grid">
      <label>Date paid
        <input type="date" name="paid_on" value="<?= h($formPaidOn) ?>" required>
      </label>
      <label>Category
        <select name="category" required>
          <?php foreach (office_expense_categories() as $slug => $label): ?>
            <option value="<?= h($slug) ?>"<?= $formCategory === $slug ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Description
        <input type="text" name="description" value="<?= h($formDescription) ?>" maxlength="255" required placeholder="Who or what this payment is for">
      </label>
      <label>Amount
        <input type="number" name="amount" value="<?= h($formAmount) ?>" min="0.01" step="0.01" required placeholder="0.00">
      </label>
      <label>Currency
        <select name="currency" required>
          <?php foreach (office_expense_currencies() as $code => $label): ?>
            <option value="<?= h($code) ?>"<?= $formCurrency === $code ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Paid by
        <select name="paid_by" required>
          <?php foreach ($admins as $adm): ?>
            <?php $aid = (int) ($adm['id'] ?? 0); ?>
            <option value="<?= $aid ?>"<?= $formPaidBy === $aid ? ' selected' : '' ?>>
              <?= h(office_expense_person_name($adm['full_name'] ?? null, $adm['username'] ?? null)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Note
        <input type="text" name="note" value="<?= h($formNote) ?>" maxlength="255" placeholder="Optional">
      </label>
    </div>
    <p class="actions" style="margin:0.85rem 0 0">
      <button class="btn" type="submit"><?= $editRow ? 'Save payment' : 'Add payment' ?></button>
      <?php if ($editRow): ?>
        <a class="btn secondary" href="<?= h($pageUrl) ?>">Cancel</a>
      <?php endif; ?>
    </p>
  </form>
</section>
<?php endif; ?>

<section class="card" id="office-expense-sheet">
  <div class="invoice-list-toolbar">
    <h2 style="margin:0"><?= h(office_expense_month_label($ym)) ?> payments</h2>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-state">
      <p><?= $open ? 'No payments this month yet. Add the first one above.' : 'No payments were recorded for this month.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="office-expense-table">
        <thead>
          <tr>
            <th>Date paid</th>
            <th>Category</th>
            <th>Description</th>
            <th class="num">Amount</th>
            <th>Paid by</th>
            <th>Typed by</th>
            <th>Note</th>
            <?php if ($open): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $rid = (int) ($row['id'] ?? 0);
            $paidName = office_expense_person_name($row['paid_by_full_name'] ?? null, $row['paid_by_username'] ?? null);
            $typedName = office_expense_person_name($row['created_by_full_name'] ?? null, $row['created_by_username'] ?? null);
            $editedName = office_expense_person_name($row['updated_by_full_name'] ?? null, $row['updated_by_username'] ?? null);
            $typedBits = $typedName;
            if ($editedName !== 'Unknown' && (int) ($row['updated_by'] ?? 0) > 0 && (int) ($row['updated_by'] ?? 0) !== (int) ($row['created_by'] ?? 0)) {
                $typedBits .= ' · last edit ' . $editedName;
            } elseif ($editedName !== 'Unknown' && (int) ($row['updated_by'] ?? 0) > 0 && (int) ($row['updated_by'] ?? 0) === (int) ($row['created_by'] ?? 0) && (string) ($row['updated_at'] ?? '') !== (string) ($row['created_at'] ?? '')) {
                $typedBits .= ' · edited';
            }
          ?>
          <tr id="row-<?= $rid ?>"<?= $editRow && (int) $editRow['id'] === $rid ? ' class="is-editing"' : '' ?>>
            <td><?= h((string) ($row['paid_on'] ?? '')) ?></td>
            <td><?= h(office_expense_category_label((string) ($row['category'] ?? 'other'))) ?></td>
            <td><?= h((string) ($row['description'] ?? '')) ?></td>
            <td class="num"><?= h(office_expense_format_amount($row['amount'] ?? 0, $row['currency'] ?? 'eur')) ?></td>
            <td><?= h($paidName) ?></td>
            <td><?= h($typedBits) ?></td>
            <td><?= h((string) ($row['note'] ?? '')) ?></td>
            <?php if ($open): ?>
              <td class="office-expense-row-actions">
                <a class="btn secondary small" href="<?= h(office_expense_page_url($ym, array_filter(['edit' => $rid, 'history_admin' => $historyAdmin ?: null]))) ?>#office-expense-add">Edit</a>
                <form method="post" action="<?= h($pageUrl) ?>" class="inline"
                      onsubmit="return confirm('Delete this payment?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $rid ?>">
                  <button class="btn secondary danger small" type="submit">Delete</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="office-expense-totals" id="office-expense-totals">
    <div>
      <h3>Totals</h3>
      <p class="office-expense-grand"><strong><?= h(office_expense_format_amount_map($totals['by_currency'] ?? [], true)) ?></strong>
        <span class="muted"><?= (int) $totals['count'] ?> payment<?= (int) $totals['count'] === 1 ? '' : 's' ?></span></p>
    </div>
    <div>
      <h3>By category</h3>
      <ul>
        <?php foreach (office_expense_categories() as $slug => $label): ?>
          <li><span><?= h($label) ?></span> <span class="num"><?= h(office_expense_format_amount_map($totals['by_category'][$slug] ?? [])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <h3>By Admin (paid by)</h3>
      <?php if (!$totals['by_admin']): ?>
        <p class="muted">No payments yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($totals['by_admin'] as $admTot): ?>
            <li><span><?= h($admTot['name']) ?></span> <span class="num"><?= h(office_expense_format_amount_map($admTot['by_currency'] ?? [])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="card" id="office-expense-history">
  <div class="invoice-list-toolbar">
    <h2 style="margin:0"><?= label_with_info('History', 'Who added, edited, deleted, saved, or reopened this month. Filter by Admin to see one person’s work.') ?></h2>
    <form method="get" action="index.php" class="office-expense-history-filter" data-no-draft>
      <input type="hidden" name="page" value="admin_office_expenses">
      <input type="hidden" name="month" value="<?= h($ym) ?>">
      <label class="visually-hidden" for="office-expense-history-admin">Filter history by Admin</label>
      <select id="office-expense-history-admin" name="history_admin" onchange="this.form.submit()">
        <option value="0">All Admins</option>
        <?php foreach ($admins as $adm): ?>
          <?php $aid = (int) ($adm['id'] ?? 0); ?>
          <option value="<?= $aid ?>"<?= $historyAdmin === $aid ? ' selected' : '' ?>>
            <?= h(office_expense_person_name($adm['full_name'] ?? null, $adm['username'] ?? null)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn small secondary" type="submit">Filter</button></noscript>
      <?php if ($historyAdmin > 0): ?>
        <a class="btn secondary small" href="<?= h(office_expense_page_url($ym)) ?>#office-expense-history">All Admins</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if (!$events): ?>
    <p class="muted"><?= $historyAdmin > 0 ? 'No history for that Admin on this month.' : 'No history yet. Add a payment to start the log.' ?></p>
  <?php else: ?>
    <ol class="office-expense-history">
      <?php foreach ($events as $ev): ?>
        <li>
          <time datetime="<?= h((string) ($ev['created_at'] ?? '')) ?>"><?= h((string) ($ev['created_at'] ?? '')) ?></time>
          <strong><?= h(office_expense_person_name($ev['actor_full_name'] ?? null, $ev['actor_username'] ?? null)) ?></strong>
          <span><?= h((string) ($ev['summary'] ?? '')) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
<?php render_footer('admin'); ?>
