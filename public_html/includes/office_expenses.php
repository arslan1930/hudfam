<?php
/**
 * Office expenses — Admin-only monthly ledger (salaries, rent, grocery, internet, other).
 */

function office_expense_categories(): array
{
    return [
        'salary' => 'Employee salaries',
        'rent' => 'Office rent',
        'grocery' => 'Office grocery',
        'internet' => 'Office internet',
        'other' => 'Other',
    ];
}

function office_expense_category_label(string $slug): string
{
    $cats = office_expense_categories();
    return $cats[$slug] ?? $cats['other'];
}

function office_expense_normalize_category(string $slug): string
{
    $slug = strtolower(trim($slug));
    $cats = office_expense_categories();
    return isset($cats[$slug]) ? $slug : 'other';
}

function office_expense_currencies(): array
{
    return [
        'eur' => 'Euro',
        'pkr' => 'PKR',
    ];
}

function office_expense_normalize_currency($value): string
{
    $v = strtolower(trim((string) $value));
    if ($v === 'euro' || $v === '€' || $v === 'eur') {
        return 'eur';
    }
    if ($v === 'pkr' || $v === 'rs' || $v === 'rupee' || $v === 'rupees' || $v === '₨') {
        return 'pkr';
    }
    $cats = office_expense_currencies();
    return isset($cats[$v]) ? $v : 'eur';
}

function office_expense_currency_label(string $code): string
{
    $list = office_expense_currencies();
    $code = office_expense_normalize_currency($code);
    return $list[$code] ?? $list['eur'];
}

/** @return array<string,float> */
function office_expense_empty_currency_map(): array
{
    $out = [];
    foreach (array_keys(office_expense_currencies()) as $code) {
        $out[$code] = 0.0;
    }
    return $out;
}

function office_expense_normalize_year_month($value): string
{
    $v = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($year >= 1990 && $year <= 2100 && $month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d', $year, $month);
        }
    }
    return date('Y-m');
}

function office_expense_month_label(string $ym): string
{
    $ym = office_expense_normalize_year_month($ym);
    $ts = strtotime($ym . '-01');
    return $ts ? date('F Y', $ts) : $ym;
}

function office_expense_shift_month(string $ym, int $delta): string
{
    $ym = office_expense_normalize_year_month($ym);
    $dt = DateTime::createFromFormat('Y-m-d', $ym . '-01');
    if (!$dt) {
        $dt = new DateTime('first day of this month');
    }
    $dt->modify(($delta >= 0 ? '+' : '') . $delta . ' month');
    return $dt->format('Y-m');
}

function office_expense_default_paid_on(string $ym): string
{
    $ym = office_expense_normalize_year_month($ym);
    $today = date('Y-m-d');
    if (substr($today, 0, 7) === $ym) {
        return $today;
    }
    return $ym . '-01';
}

function office_expense_person_name(?string $fullName, ?string $username): string
{
    $name = trim((string) $fullName);
    if ($name !== '') {
        return $name;
    }
    $name = trim((string) $username);
    return $name !== '' ? $name : 'Unknown';
}

function office_expense_format_amount($value, $currency = 'eur'): string
{
    $n = number_format(parse_money($value), 2, '.', ',');
    return office_expense_normalize_currency($currency) === 'pkr' ? ('PKR ' . $n) : ('€' . $n);
}

/** @param array<string,float|int|string> $byCurrency */
function office_expense_format_amount_map(array $byCurrency, bool $includeZero = false): string
{
    $parts = [];
    foreach (array_keys(office_expense_currencies()) as $code) {
        $amt = parse_money($byCurrency[$code] ?? 0);
        if (!$includeZero && $amt == 0.0) {
            continue;
        }
        $parts[] = office_expense_format_amount($amt, $code);
    }
    if ($parts === []) {
        return office_expense_format_amount(0, 'eur');
    }
    return implode(' · ', $parts);
}

function office_expense_page_url(string $ym, array $extra = []): string
{
    $params = array_merge(['page' => 'admin_office_expenses', 'month' => office_expense_normalize_year_month($ym)], $extra);
    $q = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $q[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return 'index.php?' . implode('&', $q);
}

function ensure_office_expense_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('txf_schema_is_current') && txf_schema_is_current(__FUNCTION__, __FILE__)) {
        return;
    }
    $pdo = db();

    $monthSql = "CREATE TABLE IF NOT EXISTS office_expense_months (
          id INT AUTO_INCREMENT PRIMARY KEY,
          bill_month CHAR(7) NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'open',
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          total_pkr DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          row_count INT NOT NULL DEFAULT 0,
          note VARCHAR(255) NOT NULL DEFAULT '',
          saved_at DATETIME NULL,
          saved_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_office_expense_month (bill_month),
          INDEX (status)";
    try {
        $pdo->exec(
            $monthSql . ",
          CONSTRAINT fk_oem_saved_by FOREIGN KEY (saved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        $pdo->exec($monthSql . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    $rowSql = "CREATE TABLE IF NOT EXISTS office_expense_rows (
          id INT AUTO_INCREMENT PRIMARY KEY,
          month_id INT NOT NULL,
          paid_on DATE NOT NULL,
          category VARCHAR(20) NOT NULL DEFAULT 'other',
          description VARCHAR(255) NOT NULL DEFAULT '',
          amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          currency VARCHAR(8) NOT NULL DEFAULT 'eur',
          paid_by INT NULL,
          note VARCHAR(255) NOT NULL DEFAULT '',
          sort_order INT NOT NULL DEFAULT 0,
          created_by INT NULL,
          updated_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (month_id, paid_on, sort_order, id),
          INDEX (paid_by),
          INDEX (category),
          INDEX (currency)";
    try {
        $pdo->exec(
            $rowSql . ",
          CONSTRAINT fk_oer_month FOREIGN KEY (month_id) REFERENCES office_expense_months(id) ON DELETE CASCADE,
          CONSTRAINT fk_oer_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_oer_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_oer_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        $pdo->exec($rowSql . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    $eventSql = "CREATE TABLE IF NOT EXISTS office_expense_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          month_id INT NOT NULL,
          row_id INT NULL,
          actor_id INT NULL,
          kind VARCHAR(40) NOT NULL,
          summary VARCHAR(500) NOT NULL DEFAULT '',
          old_value TEXT NULL,
          new_value TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (month_id, id),
          INDEX (actor_id),
          INDEX (row_id)";
    try {
        $pdo->exec(
            $eventSql . ",
          CONSTRAINT fk_oee_month FOREIGN KEY (month_id) REFERENCES office_expense_months(id) ON DELETE CASCADE,
          CONSTRAINT fk_oee_row FOREIGN KEY (row_id) REFERENCES office_expense_rows(id) ON DELETE SET NULL,
          CONSTRAINT fk_oee_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        try {
            $pdo->exec($eventSql . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (Throwable $e2) {
            // History is optional — the sheet must still load.
        }
    }

    try {
        $rowCols = $pdo->query('SHOW COLUMNS FROM office_expense_rows')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('currency', $rowCols, true)) {
            $pdo->exec("ALTER TABLE office_expense_rows ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'eur' AFTER amount");
        }
        $idxNames = [];
        foreach ($pdo->query('SHOW INDEX FROM office_expense_rows')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $idxRow) {
            $idxNames[] = (string) ($idxRow['Key_name'] ?? '');
        }
        if (!in_array('currency', $idxNames, true)) {
            try {
                $pdo->exec('ALTER TABLE office_expense_rows ADD INDEX currency (currency)');
            } catch (Throwable $eIdx) {
                // Index is optional.
            }
        }
    } catch (Throwable $e) {
        // Sheet still loads if an old Hostinger grant cannot ALTER rows.
    }
    try {
        $monthCols = $pdo->query('SHOW COLUMNS FROM office_expense_months')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('total_pkr', $monthCols, true)) {
            $pdo->exec('ALTER TABLE office_expense_months ADD COLUMN total_pkr DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount');
        }
    } catch (Throwable $e) {
        // Sheet still loads if an old Hostinger grant cannot ALTER months.
    }

    if (function_exists('txf_schema_mark_current')) {
        txf_schema_mark_current(__FUNCTION__);
    }
}

function office_expense_month_is_open(array $month): bool
{
    return strtolower(trim((string) ($month['status'] ?? 'open'))) !== 'saved';
}

function office_expense_get_month(int $id): ?array
{
    ensure_office_expense_schema();
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT m.*, u.full_name AS saved_by_full_name, u.username AS saved_by_username
         FROM office_expense_months m
         LEFT JOIN users u ON u.id = m.saved_by
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function office_expense_get_month_by_ym(string $ym): ?array
{
    ensure_office_expense_schema();
    $ym = office_expense_normalize_year_month($ym);
    $stmt = db()->prepare(
        'SELECT m.*, u.full_name AS saved_by_full_name, u.username AS saved_by_username
         FROM office_expense_months m
         LEFT JOIN users u ON u.id = m.saved_by
         WHERE m.bill_month = ? LIMIT 1'
    );
    $stmt->execute([$ym]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function office_expense_get_or_create_month(string $ym): array
{
    ensure_office_expense_schema();
    $ym = office_expense_normalize_year_month($ym);
    $existing = office_expense_get_month_by_ym($ym);
    if ($existing) {
        return $existing;
    }
    db()->prepare(
        "INSERT INTO office_expense_months (bill_month, status, total_amount, row_count)
         VALUES (?, 'open', 0.00, 0)"
    )->execute([$ym]);
    $created = office_expense_get_month((int) db()->lastInsertId());
    if (!$created) {
        $created = office_expense_get_month_by_ym($ym);
    }
    if (!$created) {
        throw new RuntimeException('Could not open that month.');
    }
    return $created;
}

/** @return list<array<string,mixed>> */
function office_expense_list_months(): array
{
    ensure_office_expense_schema();
    return db()->query(
        'SELECT m.*, u.full_name AS saved_by_full_name, u.username AS saved_by_username
         FROM office_expense_months m
         LEFT JOIN users u ON u.id = m.saved_by
         ORDER BY m.bill_month DESC'
    )->fetchAll() ?: [];
}

function office_expense_assert_open(array $month): void
{
    if (!office_expense_month_is_open($month)) {
        throw new RuntimeException('This month is saved. Reopen it to edit payments.');
    }
}

function office_expense_admin_user(int $userId, bool $activeOnly = false): ?array
{
    if ($userId < 1) {
        return null;
    }
    $sql = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function office_expense_refresh_month_snapshot(int $monthId): void
{
    ensure_office_expense_schema();
    if ($monthId < 1) {
        return;
    }
    db()->prepare(
        "UPDATE office_expense_months m
         SET total_amount = (
               SELECT COALESCE(SUM(r.amount), 0) FROM office_expense_rows r
               WHERE r.month_id = m.id AND r.currency = 'eur'
             ),
             total_pkr = (
               SELECT COALESCE(SUM(r.amount), 0) FROM office_expense_rows r
               WHERE r.month_id = m.id AND r.currency = 'pkr'
             ),
             row_count = (
               SELECT COUNT(*) FROM office_expense_rows r WHERE r.month_id = m.id
             )
         WHERE m.id = ?"
    )->execute([$monthId]);
}

/**
 * @return array{
 *   grand:float,
 *   grand_pkr:float,
 *   count:int,
 *   by_currency:array<string,float>,
 *   by_category:array<string,array<string,float>>,
 *   by_admin:list<array{user_id:int,name:string,count:int,by_currency:array<string,float>}>
 * }
 */
function office_expense_totals(int $monthId): array
{
    ensure_office_expense_schema();
    $zero = office_expense_empty_currency_map();
    $out = [
        'grand' => 0.0,
        'grand_pkr' => 0.0,
        'count' => 0,
        'by_currency' => $zero,
        'by_category' => [],
        'by_admin' => [],
    ];
    foreach (array_keys(office_expense_categories()) as $slug) {
        $out['by_category'][$slug] = office_expense_empty_currency_map();
    }
    if ($monthId < 1) {
        return $out;
    }
    $sum = db()->prepare(
        'SELECT currency, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
         FROM office_expense_rows WHERE month_id = ? GROUP BY currency'
    );
    $sum->execute([$monthId]);
    foreach ($sum->fetchAll() ?: [] as $row) {
        $cur = office_expense_normalize_currency($row['currency'] ?? 'eur');
        $out['by_currency'][$cur] += parse_money($row['total'] ?? 0);
        $out['count'] += (int) ($row['n'] ?? 0);
    }
    $out['grand'] = $out['by_currency']['eur'] ?? 0.0;
    $out['grand_pkr'] = $out['by_currency']['pkr'] ?? 0.0;

    $catStmt = db()->prepare(
        'SELECT category, currency, COALESCE(SUM(amount), 0) AS total
         FROM office_expense_rows WHERE month_id = ? GROUP BY category, currency'
    );
    $catStmt->execute([$monthId]);
    foreach ($catStmt->fetchAll() ?: [] as $row) {
        $slug = office_expense_normalize_category((string) ($row['category'] ?? 'other'));
        $cur = office_expense_normalize_currency($row['currency'] ?? 'eur');
        $out['by_category'][$slug][$cur] += parse_money($row['total'] ?? 0);
    }

    $admStmt = db()->prepare(
        'SELECT r.paid_by AS user_id,
                r.currency,
                COALESCE(SUM(r.amount), 0) AS total,
                COUNT(*) AS n,
                u.full_name, u.username
         FROM office_expense_rows r
         LEFT JOIN users u ON u.id = r.paid_by
         WHERE r.month_id = ?
         GROUP BY r.paid_by, r.currency, u.full_name, u.username'
    );
    $admStmt->execute([$monthId]);
    $admins = [];
    foreach ($admStmt->fetchAll() ?: [] as $row) {
        $uid = (int) ($row['user_id'] ?? 0);
        $cur = office_expense_normalize_currency($row['currency'] ?? 'eur');
        if (!isset($admins[$uid])) {
            $admins[$uid] = [
                'user_id' => $uid,
                'name' => office_expense_person_name($row['full_name'] ?? null, $row['username'] ?? null),
                'count' => 0,
                'by_currency' => office_expense_empty_currency_map(),
            ];
        }
        $admins[$uid]['by_currency'][$cur] += parse_money($row['total'] ?? 0);
        $admins[$uid]['count'] += (int) ($row['n'] ?? 0);
    }
    usort($admins, static function (array $a, array $b): int {
        $ae = (float) ($a['by_currency']['eur'] ?? 0);
        $be = (float) ($b['by_currency']['eur'] ?? 0);
        if ($ae !== $be) {
            return $be <=> $ae;
        }
        $ap = (float) ($a['by_currency']['pkr'] ?? 0);
        $bp = (float) ($b['by_currency']['pkr'] ?? 0);
        if ($ap !== $bp) {
            return $bp <=> $ap;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
    $out['by_admin'] = array_values($admins);
    return $out;
}

function office_expense_record_event(
    int $monthId,
    ?int $rowId,
    int $actorId,
    string $kind,
    string $summary,
    $oldValue = null,
    $newValue = null
): void {
    ensure_office_expense_schema();
    if ($monthId < 1) {
        return;
    }
    $encode = static function ($value): ?string {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    };
    try {
        db()->prepare(
            'INSERT INTO office_expense_events (month_id, row_id, actor_id, kind, summary, old_value, new_value)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $monthId,
            $rowId && $rowId > 0 ? $rowId : null,
            $actorId > 0 ? $actorId : null,
            substr(trim($kind), 0, 40),
            substr(trim($summary), 0, 500),
            $encode($oldValue),
            $encode($newValue),
        ]);
    } catch (Throwable $e) {
        // History must not block the payment.
    }
}

/** @return list<array<string,mixed>> */
function office_expense_list_events(int $monthId, int $actorId = 0): array
{
    ensure_office_expense_schema();
    if ($monthId < 1) {
        return [];
    }
    $sql = 'SELECT e.*, u.full_name AS actor_full_name, u.username AS actor_username
            FROM office_expense_events e
            LEFT JOIN users u ON u.id = e.actor_id
            WHERE e.month_id = ?';
    $params = [$monthId];
    if ($actorId > 0) {
        $sql .= ' AND e.actor_id = ?';
        $params[] = $actorId;
    }
    $sql .= ' ORDER BY e.created_at DESC, e.id DESC';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string,mixed>> */
function office_expense_list_rows(int $monthId): array
{
    ensure_office_expense_schema();
    if ($monthId < 1) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT r.*,
                pb.full_name AS paid_by_full_name, pb.username AS paid_by_username,
                cb.full_name AS created_by_full_name, cb.username AS created_by_username,
                ub.full_name AS updated_by_full_name, ub.username AS updated_by_username
         FROM office_expense_rows r
         LEFT JOIN users pb ON pb.id = r.paid_by
         LEFT JOIN users cb ON cb.id = r.created_by
         LEFT JOIN users ub ON ub.id = r.updated_by
         WHERE r.month_id = ?
         ORDER BY r.paid_on ASC, r.sort_order ASC, r.id ASC'
    );
    $stmt->execute([$monthId]);
    return $stmt->fetchAll() ?: [];
}

function office_expense_get_row(int $rowId): ?array
{
    ensure_office_expense_schema();
    if ($rowId < 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT r.*,
                pb.full_name AS paid_by_full_name, pb.username AS paid_by_username,
                cb.full_name AS created_by_full_name, cb.username AS created_by_username,
                ub.full_name AS updated_by_full_name, ub.username AS updated_by_username
         FROM office_expense_rows r
         LEFT JOIN users pb ON pb.id = r.paid_by
         LEFT JOIN users cb ON cb.id = r.created_by
         LEFT JOIN users ub ON ub.id = r.updated_by
         WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$rowId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function office_expense_row_public(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'paid_on' => (string) ($row['paid_on'] ?? ''),
        'category' => office_expense_normalize_category((string) ($row['category'] ?? 'other')),
        'description' => (string) ($row['description'] ?? ''),
        'amount' => parse_money($row['amount'] ?? 0),
        'currency' => office_expense_normalize_currency($row['currency'] ?? 'eur'),
        'paid_by' => (int) ($row['paid_by'] ?? 0),
        'note' => (string) ($row['note'] ?? ''),
    ];
}

function office_expense_normalize_payload(array $data, string $ym): array
{
    $paidOn = trim((string) ($data['paid_on'] ?? ''));
    if (function_exists('normalize_order_date')) {
        $paidOnNorm = normalize_order_date($paidOn);
    } else {
        $paidOnNorm = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) ? $paidOn : null;
    }
    if ($paidOnNorm === null) {
        throw new RuntimeException('Enter a valid date paid.');
    }
    $category = office_expense_normalize_category((string) ($data['category'] ?? 'other'));
    $description = trim((string) ($data['description'] ?? ''));
    if ($description === '') {
        throw new RuntimeException('Enter a description.');
    }
    if (mb_strlen($description) > 255) {
        $description = mb_substr($description, 0, 255);
    }
    $amount = parse_money($data['amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than 0.');
    }
    $currency = office_expense_normalize_currency($data['currency'] ?? 'eur');
    $paidBy = (int) ($data['paid_by'] ?? 0);
    $payer = office_expense_admin_user($paidBy, true);
    if (!$payer) {
        throw new RuntimeException('Pick which Admin sent the money.');
    }
    $note = trim((string) ($data['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 255);
    }
    return [
        'paid_on' => $paidOnNorm,
        'category' => $category,
        'description' => $description,
        'amount' => $amount,
        'currency' => $currency,
        'paid_by' => (int) $payer['id'],
        'note' => $note,
        'paid_by_name' => office_expense_person_name($payer['full_name'] ?? null, $payer['username'] ?? null),
        'year_month' => $ym,
    ];
}

function office_expense_add_row(int $monthId, array $data, int $actorId): int
{
    ensure_office_expense_schema();
    $month = office_expense_get_month($monthId);
    if (!$month) {
        throw new RuntimeException('Month not found.');
    }
    office_expense_assert_open($month);
    $payload = office_expense_normalize_payload($data, (string) $month['bill_month']);
    $maxStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM office_expense_rows WHERE month_id = ?');
    $maxStmt->execute([$monthId]);
    $max = (int) $maxStmt->fetchColumn();

    db()->prepare(
        'INSERT INTO office_expense_rows
            (month_id, paid_on, category, description, amount, currency, paid_by, note, sort_order, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $monthId,
        $payload['paid_on'],
        $payload['category'],
        $payload['description'],
        $payload['amount'],
        $payload['currency'],
        $payload['paid_by'],
        $payload['note'],
        $max + 10,
        $actorId > 0 ? $actorId : null,
        $actorId > 0 ? $actorId : null,
    ]);
    $id = (int) db()->lastInsertId();
    office_expense_refresh_month_snapshot($monthId);
    office_expense_record_event(
        $monthId,
        $id,
        $actorId,
        'add',
        'Added ' . office_expense_category_label($payload['category']) . ' '
            . office_expense_format_amount($payload['amount'], $payload['currency'])
            . ' — ' . $payload['description']
            . ' (paid by ' . $payload['paid_by_name'] . ')',
        null,
        office_expense_row_public(array_merge($payload, ['id' => $id]))
    );
    return $id;
}

function office_expense_update_row(int $rowId, array $data, int $actorId): void
{
    ensure_office_expense_schema();
    $row = office_expense_get_row($rowId);
    if (!$row) {
        throw new RuntimeException('Payment not found.');
    }
    $month = office_expense_get_month((int) $row['month_id']);
    if (!$month) {
        throw new RuntimeException('Month not found.');
    }
    office_expense_assert_open($month);
    $payload = office_expense_normalize_payload($data, (string) $month['bill_month']);
    $old = office_expense_row_public($row);
    $new = office_expense_row_public(array_merge($row, $payload, ['id' => $rowId]));
    $changes = [];
    foreach (['paid_on', 'category', 'description', 'amount', 'currency', 'paid_by', 'note'] as $field) {
        if ((string) ($old[$field] ?? '') !== (string) ($new[$field] ?? '')) {
            if ($field === 'amount' || $field === 'currency') {
                if ($field === 'currency' && (string) ($old['amount'] ?? '') !== (string) ($new['amount'] ?? '')) {
                    continue;
                }
                $changes[] = 'amount ' . office_expense_format_amount($old['amount'], $old['currency'] ?? 'eur')
                    . ' → ' . office_expense_format_amount($new['amount'], $new['currency'] ?? 'eur');
            } elseif ($field === 'category') {
                $changes[] = 'category ' . office_expense_category_label($old['category'])
                    . ' → ' . office_expense_category_label($new['category']);
            } elseif ($field === 'paid_by') {
                $oldName = office_expense_person_name($row['paid_by_full_name'] ?? null, $row['paid_by_username'] ?? null);
                $changes[] = 'paid by ' . $oldName . ' → ' . $payload['paid_by_name'];
            } else {
                $changes[] = str_replace('_', ' ', $field);
            }
        }
    }
    if ($changes === []) {
        return;
    }
    db()->prepare(
        'UPDATE office_expense_rows
         SET paid_on=?, category=?, description=?, amount=?, currency=?, paid_by=?, note=?, updated_by=?
         WHERE id=?'
    )->execute([
        $payload['paid_on'],
        $payload['category'],
        $payload['description'],
        $payload['amount'],
        $payload['currency'],
        $payload['paid_by'],
        $payload['note'],
        $actorId > 0 ? $actorId : null,
        $rowId,
    ]);
    office_expense_refresh_month_snapshot((int) $row['month_id']);
    office_expense_record_event(
        (int) $row['month_id'],
        $rowId,
        $actorId,
        'edit',
        'Edited ' . $payload['description'] . ': ' . implode(', ', $changes),
        $old,
        $new
    );
}

function office_expense_delete_row(int $rowId, int $actorId): void
{
    ensure_office_expense_schema();
    $row = office_expense_get_row($rowId);
    if (!$row) {
        throw new RuntimeException('Payment not found.');
    }
    $month = office_expense_get_month((int) $row['month_id']);
    if (!$month) {
        throw new RuntimeException('Month not found.');
    }
    office_expense_assert_open($month);
    $old = office_expense_row_public($row);
    office_expense_record_event(
        (int) $row['month_id'],
        $rowId,
        $actorId,
        'delete',
        'Deleted ' . office_expense_category_label($old['category']) . ' '
            . office_expense_format_amount($old['amount'], $old['currency'] ?? 'eur')
            . ' — ' . $old['description'],
        $old,
        null
    );
    db()->prepare('DELETE FROM office_expense_rows WHERE id = ?')->execute([$rowId]);
    office_expense_refresh_month_snapshot((int) $row['month_id']);
}

function office_expense_save_month(int $monthId, int $actorId): void
{
    ensure_office_expense_schema();
    $month = office_expense_get_month($monthId);
    if (!$month) {
        throw new RuntimeException('Month not found.');
    }
    if (!office_expense_month_is_open($month)) {
        throw new RuntimeException('This month is already saved.');
    }
    office_expense_refresh_month_snapshot($monthId);
    $month = office_expense_get_month($monthId) ?: $month;
    $n = (int) ($month['row_count'] ?? 0);
    $totalEur = parse_money($month['total_amount'] ?? 0);
    $totalPkr = parse_money($month['total_pkr'] ?? 0);
    db()->prepare(
        "UPDATE office_expense_months
         SET status='saved', saved_at=NOW(), saved_by=?
         WHERE id=?"
    )->execute([$actorId > 0 ? $actorId : null, $monthId]);
    office_expense_record_event(
        $monthId,
        null,
        $actorId,
        'save_month',
        'Saved ' . office_expense_month_label((string) $month['bill_month'])
            . ' · ' . $n . ' payment' . ($n === 1 ? '' : 's')
            . ' · ' . office_expense_format_amount_map(['eur' => $totalEur, 'pkr' => $totalPkr], true),
        ['status' => 'open'],
        ['status' => 'saved', 'total' => $totalEur, 'total_pkr' => $totalPkr, 'count' => $n]
    );
}

function office_expense_reopen_month(int $monthId, int $actorId): void
{
    ensure_office_expense_schema();
    $month = office_expense_get_month($monthId);
    if (!$month) {
        throw new RuntimeException('Month not found.');
    }
    if (office_expense_month_is_open($month)) {
        throw new RuntimeException('This month is already open.');
    }
    db()->prepare(
        "UPDATE office_expense_months
         SET status='open'
         WHERE id=?"
    )->execute([$monthId]);
    office_expense_record_event(
        $monthId,
        null,
        $actorId,
        'reopen_month',
        'Reopened ' . office_expense_month_label((string) $month['bill_month']),
        ['status' => 'saved'],
        ['status' => 'open']
    );
}

/**
 * @return array{ok:bool,year_month:string,status:string,total:float,total_pkr:float,count:int,by_currency:array<string,float>}
 */
function office_expense_current_month_stats(): array
{
    $ym = date('Y-m');
    $out = [
        'ok' => true,
        'year_month' => $ym,
        'status' => 'open',
        'total' => 0.0,
        'total_pkr' => 0.0,
        'count' => 0,
        'by_currency' => office_expense_empty_currency_map(),
    ];
    try {
        ensure_office_expense_schema();
        $month = office_expense_get_month_by_ym($ym);
        if ($month) {
            $out['status'] = office_expense_month_is_open($month) ? 'open' : 'saved';
            $out['total'] = parse_money($month['total_amount'] ?? 0);
            $out['total_pkr'] = parse_money($month['total_pkr'] ?? 0);
            $out['count'] = (int) ($month['row_count'] ?? 0);
            $out['by_currency']['eur'] = $out['total'];
            $out['by_currency']['pkr'] = $out['total_pkr'];
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
    }
    return $out;
}

function office_expense_month_jumper_options(string $currentYm): array
{
    $currentYm = office_expense_normalize_year_month($currentYm);
    $seen = [];
    $out = [];
    $add = static function (string $ym) use (&$seen, &$out): void {
        $ym = office_expense_normalize_year_month($ym);
        if (isset($seen[$ym])) {
            return;
        }
        $seen[$ym] = true;
        $out[] = [
            'value' => $ym,
            'label' => office_expense_month_label($ym),
        ];
    };
    $add($currentYm);
    $add(date('Y-m'));
    foreach (office_expense_list_months() as $row) {
        $add((string) ($row['bill_month'] ?? ''));
    }
    // Nearby months so the jumper can start a new bill without typing.
    for ($i = -2; $i <= 1; $i++) {
        $add(office_expense_shift_month($currentYm, $i));
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) $b['value'], (string) $a['value']);
    });
    return $out;
}
