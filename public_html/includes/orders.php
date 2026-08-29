<?php
/**
 * Admin Order Management — one pipeline sheet (country, date, admin, client email/name).
 */

function ensure_order_schema(): void
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_clients (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(200) NOT NULL,
          notes TEXT NULL,
          is_archived TINYINT(1) NOT NULL DEFAULT 0,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_order_client_name (name),
          INDEX (created_by),
          INDEX idx_order_clients_archived (is_archived),
          CONSTRAINT fk_oc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          client_id INT NULL,
          row_type ENUM('site','year_end') NOT NULL DEFAULT 'site',
          site_name VARCHAR(255) NOT NULL DEFAULT '',
          site_note VARCHAR(255) NOT NULL DEFAULT '',
          placement_type VARCHAR(20) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          order_month TINYINT NULL,
          period_end_month TINYINT NULL,
          order_year SMALLINT NOT NULL DEFAULT 0,
          owner_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          decided_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          live_url VARCHAR(500) NOT NULL DEFAULT '',
          client_label VARCHAR(255) NOT NULL DEFAULT '',
          admin_user_id INT NULL,
          order_date DATE NULL,
          is_paid TINYINT(1) NOT NULL DEFAULT 0,
          site_price_row_id INT NULL,
          order_stage ENUM('processing','completed') NOT NULL DEFAULT 'processing',
          sort_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (client_id, sort_order),
          INDEX (client_id, order_year, order_month),
          INDEX idx_oi_admin (admin_user_id),
          INDEX idx_oi_order_date (order_date),
          INDEX idx_oi_country (country),
          UNIQUE KEY uniq_order_items_site_price_row (site_price_row_id),
          INDEX idx_oi_order_stage (order_stage),
          CONSTRAINT fk_oi_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE SET NULL,
          CONSTRAINT fk_oi_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Migrate older order_items tables that pre-date country/month/year/row_type
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_COLUMN);
        $alters = [];
        if (!in_array('row_type', $cols, true)) {
            $alters[] = "ADD COLUMN row_type ENUM('site','year_end') NOT NULL DEFAULT 'site' AFTER client_id";
        }
        if (!in_array('country', $cols, true)) {
            $alters[] = "ADD COLUMN country VARCHAR(100) NOT NULL DEFAULT '' AFTER site_name";
        }
        if (!in_array('site_note', $cols, true)) {
            $alters[] = "ADD COLUMN site_note VARCHAR(255) NOT NULL DEFAULT '' AFTER site_name";
        }
        if (!in_array('placement_type', $cols, true)) {
            $alters[] = "ADD COLUMN placement_type VARCHAR(20) NOT NULL DEFAULT '' AFTER site_note";
        }
        if (!in_array('order_month', $cols, true)) {
            $alters[] = 'ADD COLUMN order_month TINYINT NULL AFTER country';
        }
        if (!in_array('period_end_month', $cols, true)) {
            $alters[] = 'ADD COLUMN period_end_month TINYINT NULL AFTER order_month';
        }
        if (!in_array('order_year', $cols, true)) {
            $alters[] = 'ADD COLUMN order_year SMALLINT NOT NULL DEFAULT 0 AFTER period_end_month';
        }
        if (!in_array('is_paid', $cols, true)) {
            $alters[] = 'ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER live_url';
        }
        if ($alters) {
            $pdo->exec('ALTER TABLE order_items ' . implode(', ', $alters));
        }
        // Backfill year for old rows
        $pdo->exec(
            'UPDATE order_items SET order_year = YEAR(created_at)
             WHERE order_year = 0 OR order_year IS NULL'
        );
        $pdo->exec(
            'UPDATE order_items SET order_month = MONTH(created_at)
             WHERE order_month IS NULL AND row_type = \'site\''
        );
        $cols = $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_COLUMN);
        $pipelineAlters = [];
        if (!in_array('client_label', $cols, true)) {
            $pipelineAlters[] = "ADD COLUMN client_label VARCHAR(255) NOT NULL DEFAULT '' AFTER live_url";
        }
        if (!in_array('admin_user_id', $cols, true)) {
            $pipelineAlters[] = 'ADD COLUMN admin_user_id INT NULL AFTER client_label';
        }
        if (!in_array('order_date', $cols, true)) {
            $pipelineAlters[] = 'ADD COLUMN order_date DATE NULL AFTER admin_user_id';
        }
        if ($pipelineAlters) {
            $pdo->exec('ALTER TABLE order_items ' . implode(', ', $pipelineAlters));
        }
        $clientCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'client_id'")->fetch(PDO::FETCH_ASSOC);
        if ($clientCol && strtoupper((string) ($clientCol['Null'] ?? '')) === 'NO') {
            $pdo->exec('ALTER TABLE order_items MODIFY client_id INT NULL');
        }
        $idxNames = [];
        foreach ($pdo->query('SHOW INDEX FROM order_items')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $idxNames[(string) ($idx['Key_name'] ?? '')] = true;
        }
        if (empty($idxNames['idx_oi_admin'])) {
            $pdo->exec('ALTER TABLE order_items ADD INDEX idx_oi_admin (admin_user_id)');
        }
        if (empty($idxNames['idx_oi_order_date'])) {
            $pdo->exec('ALTER TABLE order_items ADD INDEX idx_oi_order_date (order_date)');
        }
        if (empty($idxNames['idx_oi_country'])) {
            $pdo->exec('ALTER TABLE order_items ADD INDEX idx_oi_country (country)');
        }
        try {
            $pdo->exec(
                'ALTER TABLE order_items
                 ADD CONSTRAINT fk_oi_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL'
            );
        } catch (Throwable $e) {
            // already exists
        }
        $pdo->exec(
            "UPDATE order_items i
             INNER JOIN order_clients c ON c.id = i.client_id
             SET i.client_label = c.name
             WHERE i.row_type = 'site' AND TRIM(i.client_label) = '' AND TRIM(c.name) <> ''"
        );
        $pdo->exec(
            "UPDATE order_items i
             INNER JOIN order_clients c ON c.id = i.client_id
             SET i.admin_user_id = c.created_by
             WHERE i.admin_user_id IS NULL AND c.created_by IS NOT NULL"
        );
        $pdo->exec(
            "UPDATE order_items
             SET order_date = CASE
               WHEN order_year >= 2000 AND order_month BETWEEN 1 AND 12
                 THEN STR_TO_DATE(CONCAT(order_year, '-', LPAD(order_month, 2, '0'), '-01'), '%Y-%m-%d')
               ELSE DATE(created_at)
             END
             WHERE order_date IS NULL AND row_type = 'site'"
        );
        $cols = $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_COLUMN);
        $folderAlters = [];
        $stageWasMissing = !in_array('order_stage', $cols, true);
        if (!in_array('site_price_row_id', $cols, true)) {
            $folderAlters[] = 'ADD COLUMN site_price_row_id INT NULL AFTER is_paid';
        }
        if ($stageWasMissing) {
            $folderAlters[] = "ADD COLUMN order_stage ENUM('processing','completed') NOT NULL DEFAULT 'processing' AFTER site_price_row_id";
        }
        if ($folderAlters) {
            $pdo->exec('ALTER TABLE order_items ' . implode(', ', $folderAlters));
        }
        $idxNames = [];
        foreach ($pdo->query('SHOW INDEX FROM order_items')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $idxNames[(string) ($idx['Key_name'] ?? '')] = true;
        }
        if (empty($idxNames['uniq_order_items_site_price_row'])) {
            try {
                $pdo->exec('ALTER TABLE order_items ADD UNIQUE INDEX uniq_order_items_site_price_row (site_price_row_id)');
            } catch (Throwable $e) {
                // duplicate values on old rows — skip unique
            }
        }
        if (empty($idxNames['idx_oi_order_stage'])) {
            try {
                $pdo->exec('ALTER TABLE order_items ADD INDEX idx_oi_order_stage (order_stage)');
            } catch (Throwable $e) {
                // already exists
            }
        }
        $wpExists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_price_rows'"
        )->fetchColumn();
        if ($wpExists > 0) {
            try {
                $pdo->exec(
                    'ALTER TABLE order_items
                     ADD CONSTRAINT fk_order_items_site_price_row
                     FOREIGN KEY (site_price_row_id) REFERENCES site_price_rows(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e) {
                // already exists, or Website prices table missing on older installs
            }
        }
        if ($stageWasMissing) {
            $pdo->exec(
                "UPDATE order_items SET order_stage = 'completed'
                 WHERE row_type = 'site' AND TRIM(COALESCE(live_url, '')) <> ''"
            );
        }
    } catch (Throwable $e) {
        // ignore on fresh installs
    }

    try {
        $cCols = $pdo->query('SHOW COLUMNS FROM order_clients')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_archived', $cCols, true)) {
            $pdo->exec(
                'ALTER TABLE order_clients
                 ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
                 ADD INDEX idx_order_clients_archived (is_archived)'
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    if (function_exists('txf_schema_mark_current')) {
        txf_schema_mark_current(__FUNCTION__);
    }
}

/** @return array<int,string> */
function order_month_names(): array
{
    return [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];
}

function order_month_label(?int $month): string
{
    $names = order_month_names();
    return $names[(int) $month] ?? '';
}

/** @return array<string,string> */
function order_placement_options(): array
{
    return [
        '' => '—',
        'textlink' => 'Textlink',
        'banner' => 'Banner',
    ];
}

function normalize_placement_type($value): string
{
    $v = strtolower(trim((string) $value));
    return in_array($v, ['textlink', 'banner'], true) ? $v : '';
}

function order_is_placement(array $row): bool
{
    return normalize_placement_type($row['placement_type'] ?? '') !== '';
}

function order_placement_label(array $row): string
{
    $type = normalize_placement_type($row['placement_type'] ?? '');
    if ($type === 'banner') {
        return 'Banner';
    }
    if ($type === 'textlink') {
        return 'Textlink';
    }
    return '';
}

/** Invoice / display description for a sheet row. */
function order_invoice_description(array $row): string
{
    if (order_is_placement($row)) {
        $label = order_placement_label($row);
        $site = trim((string) ($row['site_name'] ?? ''));
        $start = order_month_label((int) ($row['order_month'] ?? 0));
        $end = order_month_label((int) ($row['period_end_month'] ?? 0));
        if ($start === '') {
            $start = '—';
        }
        if ($end === '') {
            $end = '—';
        }
        $sitePart = $site !== '' ? ' (' . $site . ')' : '';
        return $label . ' per year' . $sitePart . ' starting ' . $start . ' ending ' . $end;
    }
    $url = trim((string) ($row['live_url'] ?? ''));
    return 'Article Published -' . "\n" . $url;
}

function parse_money($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['', ''], trim($value));
    }
    if (!is_numeric($value)) {
        return 0.0;
    }
    return round((float) $value, 2);
}

function format_money($value): string
{
    return number_format(parse_money($value), 2, '.', '');
}

function normalize_order_date($value): ?string
{
    $v = trim((string) $value);
    if ($v === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return null;
    }
    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function order_admin_display_name(array $row): string
{
    $name = trim((string) ($row['admin_full_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return trim((string) ($row['admin_username'] ?? ''));
}

/** @return list<array<string,mixed>> */
function order_admin_options(): array
{
    if (function_exists('list_admin_users')) {
        return list_admin_users(true);
    }
    return db()->query(
        "SELECT * FROM users WHERE role='admin' AND is_active=1 ORDER BY full_name, username"
    )->fetchAll();
}

function order_profit($ownerPrice, $decidedPrice): float
{
    return round(parse_money($decidedPrice) - parse_money($ownerPrice), 2);
}

function order_stage(array $row): string
{
    $stage = strtolower(trim((string) ($row['order_stage'] ?? 'processing')));
    return $stage === 'completed' ? 'completed' : 'processing';
}

function order_is_completed(array $row): bool
{
    if (($row['row_type'] ?? 'site') === 'year_end') {
        return false;
    }
    return order_stage($row) === 'completed' && trim((string) ($row['live_url'] ?? '')) !== '';
}

/** LIVE URL + country + client — required to mark completed or push to an invoice. */
function order_row_ready_for_complete(array $row): bool
{
    return trim((string) ($row['live_url'] ?? '')) !== ''
        && trim((string) ($row['country'] ?? '')) !== ''
        && trim((string) ($row['client_label'] ?? '')) !== '';
}

function order_row_ready_for_invoice(array $row): bool
{
    return order_is_completed($row) && !order_is_paid($row) && order_row_ready_for_complete($row);
}

function get_order_item_by_site_price_row(int $sitePriceRowId): ?array
{
    ensure_order_schema();
    if ($sitePriceRowId < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM order_items WHERE site_price_row_id = ? LIMIT 1');
    $stmt->execute([$sitePriceRowId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Optional: first number in a Website prices note becomes OM owner price. */
function order_owner_price_from_price_note(string $note): ?float
{
    $note = trim($note);
    if ($note === '') {
        return null;
    }
    if (!preg_match('/(\d+(?:[.,]\d{1,2})?)/', $note, $m)) {
        return null;
    }
    $n = (float) str_replace(',', '.', $m[1]);
    return $n > 0 ? round($n, 2) : null;
}

/**
 * Website prices Processing → Admin OM Processing.
 * Never copies reply email, LIVE URL, profit, or invoice fields onto Website prices.
 * Never pulls a completed OM row back into Processing.
 */
function order_sync_from_site_price_row(int $sitePriceRowId): void
{
    if ($sitePriceRowId < 1 || !function_exists('get_site_price_row')) {
        return;
    }
    $wp = get_site_price_row($sitePriceRowId);
    if (!$wp) {
        return;
    }
    $wpStatus = strtolower(trim((string) ($wp['status_slug'] ?? '')));
    $siteName = trim((string) ($wp['domain'] ?? ''));
    $country = trim((string) ($wp['country'] ?? ''));
    $existing = get_order_item_by_site_price_row($sitePriceRowId);

    if ($wpStatus !== 'processing') {
        return;
    }
    if ($existing && order_stage($existing) === 'completed') {
        return;
    }
    if ($existing) {
        $fields = [
            'site_name' => $siteName !== '' ? $siteName : (string) ($existing['site_name'] ?? ''),
            'country' => $country !== '' ? $country : (string) ($existing['country'] ?? ''),
        ];
        $owner = order_owner_price_from_price_note((string) ($wp['price_note'] ?? ''));
        if ($owner !== null && parse_money($existing['owner_price'] ?? 0) <= 0) {
            $fields['owner_price'] = $owner;
        }
        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $sets[] = $col . '=?';
            $params[] = $val;
        }
        $params[] = (int) $existing['id'];
        db()->prepare(
            'UPDATE order_items SET ' . implode(', ', $sets) . ', updated_at=NOW()
             WHERE id=? AND COALESCE(order_stage, \'processing\') = \'processing\''
        )->execute($params);
        return;
    }
    if ($siteName === '') {
        return;
    }
    add_order_item(null, $siteName, null, null, [
        'country' => $country,
        'site_price_row_id' => $sitePriceRowId,
        'owner_price' => order_owner_price_from_price_note((string) ($wp['price_note'] ?? '')),
        'order_stage' => 'processing',
    ]);
}

function order_reconcile_processing_from_website_prices(): void
{
    if (!function_exists('ensure_site_prices_schema')) {
        return;
    }
    try {
        ensure_site_prices_schema();
        $ids = db()->query(
            "SELECT id FROM site_price_rows WHERE status_slug = 'processing'"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    foreach ($ids as $id) {
        try {
            order_sync_from_site_price_row((int) $id);
        } catch (Throwable $e) {
            // best-effort — a bad Website prices row must not block the folder
        }
    }
}

/**
 * @return array{ok:bool,error?:string,item?:?array}
 */
function order_mark_completed(int $id, string $liveUrl, int $userId): array
{
    ensure_order_schema();
    $item = get_order_item($id);
    if (!$item || ($item['row_type'] ?? 'site') === 'year_end') {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    $liveUrl = trim($liveUrl);
    if ($liveUrl === '') {
        $liveUrl = trim((string) ($item['live_url'] ?? ''));
    }
    if ($liveUrl === '') {
        return ['ok' => false, 'error' => 'A live URL is required to mark an order completed.'];
    }
    $country = trim((string) ($item['country'] ?? ''));
    if ($country === '') {
        return ['ok' => false, 'error' => 'Country is required to mark an order completed.'];
    }
    $client = trim((string) ($item['client_label'] ?? ''));
    if ($client === '') {
        return ['ok' => false, 'error' => 'Client email or name is required to mark an order completed.'];
    }
    db()->prepare(
        "UPDATE order_items
         SET live_url=?, order_stage='completed', is_paid=0, updated_at=NOW()
         WHERE id=? AND row_type='site'"
    )->execute([$liveUrl, $id]);

    $wpId = (int) ($item['site_price_row_id'] ?? 0);
    if ($wpId > 0 && function_exists('site_price_save_row') && function_exists('get_site_price_row')) {
        if (function_exists('site_price_flush_status_cache')) {
            site_price_flush_status_cache();
        }
        $wp = get_site_price_row($wpId);
        if ($wp) {
            try {
                site_price_save_row($wpId, ['status_slug' => 'completed'], [
                    'id' => $userId,
                    'role' => 'admin',
                ]);
            } catch (Throwable $e) {
                // OM row is already completed; Website prices status is best-effort
            }
        }
    }

    return ['ok' => true, 'item' => get_order_item($id)];
}

/**
 * @param list<int> $ids
 * @param array<int|string,string> $liveUrlsById
 * @return array{ok:int,errors:list<string>}
 */
function order_mark_items_completed(array $ids, array $liveUrlsById, int $userId): array
{
    $ok = 0;
    $errors = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id < 1) {
            continue;
        }
        $url = trim((string) ($liveUrlsById[$id] ?? $liveUrlsById[(string) $id] ?? ''));
        $res = order_mark_completed($id, $url, $userId);
        if (!empty($res['ok'])) {
            $ok++;
        } else {
            $errors[] = 'Order #' . $id . ': ' . (string) ($res['error'] ?? 'Could not complete.');
        }
    }
    return ['ok' => $ok, 'errors' => $errors];
}

function order_is_paid(array $row): bool
{
    if (($row['row_type'] ?? 'site') === 'year_end') {
        return false;
    }
    return (int) ($row['is_paid'] ?? 0) === 1;
}

/**
 * Mark / unmark a site row paid.
 * Marking paid requires a LIVE URL (completed order).
 */
function set_order_item_paid(int $itemId, int $clientId, bool $paid): void
{
    ensure_order_schema();
    $sql = "SELECT live_url, order_stage FROM order_items WHERE id=? AND row_type='site'";
    $params = [$itemId];
    if ($clientId > 0) {
        $sql .= ' AND client_id=?';
        $params[] = $clientId;
    }
    $sql .= ' LIMIT 1';
    if ($paid) {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Order row not found.');
        }
        if (!order_is_completed($row)) {
            throw new InvalidArgumentException(
                'Fill LIVE URL and mark the order completed before marking a row as paid (completed orders only).'
            );
        }
    }
    $upd = "UPDATE order_items SET is_paid=?, updated_at=NOW() WHERE id=? AND row_type='site'";
    $updParams = [$paid ? 1 : 0, $itemId];
    if ($clientId > 0) {
        $upd .= ' AND client_id=?';
        $updParams[] = $clientId;
    }
    db()->prepare($upd)->execute($updParams);
    if ($clientId > 0) {
        db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    }
}

/** True if another client already uses this name (case-sensitive DB unique). */
function order_client_name_taken(string $name, int $excludeId = 0): bool
{
    ensure_order_schema();
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT id FROM order_clients WHERE name=? AND id<>? LIMIT 1'
    );
    $stmt->execute([$name, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

/** Invoices still linked to this order client (before delete orphans them). */
function count_invoices_for_order_client(int $clientId): int
{
    if ($clientId <= 0) {
        return 0;
    }
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM invoices WHERE client_id=?');
        $stmt->execute([$clientId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @param array{filter?:string,sort?:string,q?:string} $opts
 * @return list<array<string,mixed>>
 */
function list_order_clients(array $opts = []): array
{
    ensure_order_schema();
    $filter = (string) ($opts['filter'] ?? 'all');
    $sort = (string) ($opts['sort'] ?? 'name');
    $q = trim((string) ($opts['q'] ?? ''));
    $includeArchived = !empty($opts['include_archived']) || $filter === 'archived';
    $limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
    $offset = max(0, (int) ($opts['offset'] ?? 0));

    [$whereSql, $params] = order_clients_where_sql($filter, $q, $includeArchived);

    $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site') AS item_count,
                (SELECT COUNT(*) FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site'
                    AND TRIM(i.live_url) <> '') AS completed_count,
                (SELECT COALESCE(SUM(i.decided_price - i.owner_price), 0)
                   FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site'
                    AND TRIM(i.live_url) <> '') AS completed_profit,
                (SELECT COALESCE(SUM(i.decided_price - i.owner_price), 0)
                   FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site') AS total_profit,
                (SELECT COUNT(*) FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site'
                    AND TRIM(i.live_url) <> ''
                    AND COALESCE(i.is_paid, 0) = 0) AS unpaid_live_count,
                (SELECT COALESCE(SUM(i.decided_price), 0) FROM order_items i
                  WHERE i.client_id = c.id AND i.row_type = 'site'
                    AND TRIM(i.live_url) <> ''
                    AND COALESCE(i.is_paid, 0) = 0) AS unpaid_decided
         FROM order_clients c"
        . $whereSql;
    if ($sort === 'updated') {
        $sql .= ' ORDER BY c.updated_at DESC, c.name ASC';
    } elseif ($sort === 'unpaid') {
        $sql .= ' ORDER BY unpaid_decided DESC, c.name ASC';
    } else {
        $sql .= ' ORDER BY c.name ASC';
    }
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @return array{0:string,1:list<mixed>}
 */
function order_clients_where_sql(string $filter, string $q, bool $includeArchived): array
{
    $where = [];
    $params = [];
    if ($filter === 'archived') {
        $where[] = 'COALESCE(c.is_archived, 0) = 1';
    } elseif (!$includeArchived) {
        $where[] = 'COALESCE(c.is_archived, 0) = 0';
    }
    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.notes LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($filter === 'unpaid') {
        $where[] = "EXISTS (
            SELECT 1 FROM order_items i
            WHERE i.client_id = c.id AND i.row_type = 'site'
              AND TRIM(i.live_url) <> '' AND COALESCE(i.is_paid, 0) = 0
        )";
    } elseif ($filter === 'completed') {
        $where[] = "EXISTS (
            SELECT 1 FROM order_items i
            WHERE i.client_id = c.id AND i.row_type = 'site'
              AND TRIM(i.live_url) <> ''
        )";
    }
    $sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    return [$sql, $params];
}

function count_order_clients(array $opts = []): int
{
    ensure_order_schema();
    $filter = (string) ($opts['filter'] ?? 'all');
    $q = trim((string) ($opts['q'] ?? ''));
    $includeArchived = !empty($opts['include_archived']) || $filter === 'archived';
    [$whereSql, $params] = order_clients_where_sql($filter, $q, $includeArchived);
    $stmt = db()->prepare('SELECT COUNT(*) FROM order_clients c' . $whereSql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Count unpaid LIVE rows for one client (invoice-ready). */
function count_order_client_unpaid_live(int $clientId): int
{
    ensure_order_schema();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM order_items
         WHERE client_id=? AND row_type='site'
           AND COALESCE(order_stage, 'processing') = 'completed'
           AND TRIM(live_url) <> '' AND COALESCE(is_paid, 0) = 0"
    );
    $stmt->execute([$clientId]);
    return (int) $stmt->fetchColumn();
}

/** Dashboard/order list aggregates for the pipeline sheet. */
function order_management_dashboard_stats(): array
{
    ensure_order_schema();
    $orders = (int) db()->query(
        "SELECT COUNT(*) FROM order_items WHERE row_type = 'site'"
    )->fetchColumn();
    $processing = count_order_pipeline_rows(['folder' => 'processing']);
    $completed = count_order_pipeline_rows(['folder' => 'completed']);
    $unpaidLive = count_order_pipeline_rows(['folder' => 'completed', 'status' => 'unpaid']);
    return [
        'orders' => $orders,
        'clients' => $orders,
        'processing' => $processing,
        'completed' => $completed,
        'unpaid_live' => $unpaidLive,
    ];
}

function get_order_client(int $id): ?array
{
    ensure_order_schema();
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM order_clients WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_order_client(string $name, string $notes, ?int $createdBy): int
{
    ensure_order_schema();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Client name is required.');
    }
    if (order_client_name_taken($name)) {
        throw new InvalidArgumentException('A client named “' . $name . '” already exists.');
    }
    $stmt = db()->prepare(
        'INSERT INTO order_clients (name, notes, created_by) VALUES (?,?,?)'
    );
    $stmt->execute([$name, trim($notes), $createdBy]);
    return (int) db()->lastInsertId();
}

function update_order_client(int $id, string $name, string $notes): void
{
    ensure_order_schema();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Client name is required.');
    }
    if (order_client_name_taken($name, $id)) {
        throw new InvalidArgumentException('A client named “' . $name . '” already exists.');
    }
    db()->prepare(
        'UPDATE order_clients SET name=?, notes=?, updated_at=NOW() WHERE id=?'
    )->execute([$name, trim($notes), $id]);
}

function delete_order_client(int $id): void
{
    ensure_order_schema();
    db()->prepare('DELETE FROM order_clients WHERE id=?')->execute([$id]);
}

function set_order_client_archived(int $id, bool $archived): void
{
    ensure_order_schema();
    db()->prepare(
        'UPDATE order_clients SET is_archived=?, updated_at=NOW() WHERE id=?'
    )->execute([$archived ? 1 : 0, $id]);
}

function order_client_is_archived(array $client): bool
{
    return (int) ($client['is_archived'] ?? 0) === 1;
}

function next_order_sort(?int $clientId = null): int
{
    if ($clientId !== null && $clientId > 0) {
        $max = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM order_items WHERE client_id=?');
        $max->execute([$clientId]);
        return (int) $max->fetchColumn() + 1;
    }
    $max = db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM order_items');
    return (int) $max->fetchColumn() + 1;
}

/**
 * @return list<array<string,mixed>>
 */
function list_order_items(int $clientId): array
{
    ensure_order_schema();
    $stmt = db()->prepare(
        "SELECT * FROM order_items
         WHERE client_id=?
         ORDER BY
           CASE WHEN order_year = 0 THEN 9999 ELSE order_year END ASC,
           CASE WHEN row_type = 'year_end' THEN 1 ELSE 0 END ASC,
           CASE WHEN order_month IS NULL THEN 13 ELSE order_month END ASC,
           sort_order ASC,
           id ASC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

function add_order_item(?int $clientId = null, string $siteName = '', ?int $month = null, ?int $year = null, array $extra = []): int
{
    ensure_order_schema();
    $month = $month ?? (int) date('n');
    $year = $year ?? (int) date('Y');
    if ($month < 1 || $month > 12) {
        $month = (int) date('n');
    }
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    $clientId = ($clientId !== null && $clientId > 0) ? $clientId : null;
    $next = next_order_sort($clientId);
    $label = trim((string) ($extra['client_label'] ?? ''));
    if (mb_strlen($label) > 255) {
        $label = mb_substr($label, 0, 255);
    }
    $adminId = (int) ($extra['admin_user_id'] ?? 0);
    $adminId = $adminId > 0 ? $adminId : null;
    $orderDate = normalize_order_date($extra['order_date'] ?? '') ?: date('Y-m-d');
    $country = trim((string) ($extra['country'] ?? ''));
    $wpId = (int) ($extra['site_price_row_id'] ?? 0);
    $stage = strtolower(trim((string) ($extra['order_stage'] ?? 'processing')));
    if ($stage !== 'completed') {
        $stage = 'processing';
    }
    $ownerPrice = 0.0;
    if (array_key_exists('owner_price', $extra) && $extra['owner_price'] !== null && $extra['owner_price'] !== '') {
        $ownerPrice = parse_money($extra['owner_price']);
    }
    try {
        db()->prepare(
            "INSERT INTO order_items
              (client_id, row_type, site_name, country, order_month, order_year,
               owner_price, decided_price, live_url, client_label, admin_user_id, order_date, sort_order,
               site_price_row_id, order_stage)
             VALUES (?, 'site', ?, ?, ?, ?, ?, 0, '', ?, ?, ?, ?, ?, ?)"
        )->execute([
            $clientId,
            trim($siteName),
            $country,
            $month,
            $year,
            $ownerPrice,
            $label,
            $adminId,
            $orderDate,
            $next,
            $wpId > 0 ? $wpId : null,
            $stage,
        ]);
    } catch (PDOException $e) {
        if ($wpId > 0 && $e->getCode() === '23000') {
            $existing = get_order_item_by_site_price_row($wpId);
            if ($existing) {
                return (int) $existing['id'];
            }
        }
        throw $e;
    }
    $id = (int) db()->lastInsertId();
    if ($clientId) {
        db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    }
    return $id;
}

/**
 * Insert a full-width year-end marker between years.
 */
function add_order_year_end(int $clientId, int $endingYear): int
{
    ensure_order_schema();
    if ($endingYear < 2000 || $endingYear > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }
    $next = next_order_sort($clientId);
    db()->prepare(
        "INSERT INTO order_items
          (client_id, row_type, site_name, country, order_month, order_year, owner_price, decided_price, live_url, sort_order)
         VALUES (?, 'year_end', '', '', 12, ?, 0, 0, '', ?)"
    )->execute([$clientId, $endingYear, $next]);
    $id = (int) db()->lastInsertId();
    // Seed first row of the new year so admin can keep filling
    add_order_item($clientId, '', 1, $endingYear + 1);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    return $id;
}

/**
 * @param array{site_name?:string,site_note?:string,placement_type?:string,country?:string,order_month?:mixed,period_end_month?:mixed,order_year?:mixed,owner_price?:mixed,decided_price?:mixed,live_url?:string} $data
 */
function update_order_item(int $itemId, int $clientId, array $data): void
{
    ensure_order_schema();
    $month = (int) ($data['order_month'] ?? 0);
    if ($month < 1 || $month > 12) {
        $month = null;
    }
    $endMonth = (int) ($data['period_end_month'] ?? 0);
    if ($endMonth < 1 || $endMonth > 12) {
        $endMonth = null;
    }
    $year = (int) ($data['order_year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    $placement = normalize_placement_type($data['placement_type'] ?? '');
    if ($placement === '') {
        $endMonth = null;
    }
    $note = trim((string) ($data['site_note'] ?? ''));
    if (mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 255);
    }
    $siteName = trim((string) ($data['site_name'] ?? ''));
    $liveUrl = trim((string) ($data['live_url'] ?? ''));
    $ownerPrice = parse_money($data['owner_price'] ?? 0);
    $decidedPrice = parse_money($data['decided_price'] ?? 0);

    // LIVE URL means the order is complete — price fields must not be empty.
    if ($liveUrl !== '') {
        $ownerRaw = trim((string) ($data['owner_price'] ?? ''));
        $decidedRaw = trim((string) ($data['decided_price'] ?? ''));
        if ($ownerRaw === '' || $decidedRaw === '') {
            $label = $siteName !== '' ? $siteName : ('row #' . $itemId);
            throw new InvalidArgumentException(
                'When LIVE URL is filled, Owner price and Decided price cannot be empty — check ' . $label . '.'
            );
        }
        if ($decidedPrice <= 0) {
            $label = $siteName !== '' ? $siteName : ('row #' . $itemId);
            throw new InvalidArgumentException(
                'When LIVE URL is filled, Decided price must be greater than 0 — check ' . $label . '.'
            );
        }
        if ($placement !== '' && $siteName === '') {
            throw new InvalidArgumentException('Banner / Textlink rows need a site name when LIVE URL is filled.');
        }
    }

    $sets = [
        'site_name=?',
        'site_note=?',
        'placement_type=?',
        'country=?',
        'order_month=?',
        'period_end_month=?',
        'order_year=?',
        'owner_price=?',
        'decided_price=?',
        'live_url=?',
        "is_paid=IF(? = '', 0, is_paid)",
        "order_stage=IF(? = '', 'processing', order_stage)",
        'updated_at=NOW()',
    ];
    $params = [
        $siteName,
        $note,
        $placement,
        trim((string) ($data['country'] ?? '')),
        $month,
        $endMonth,
        $year,
        $ownerPrice,
        $decidedPrice,
        $liveUrl,
        $liveUrl,
        $liveUrl,
    ];
    if (array_key_exists('client_label', $data)) {
        $label = trim((string) $data['client_label']);
        if (mb_strlen($label) > 255) {
            $label = mb_substr($label, 0, 255);
        }
        $sets[] = 'client_label=?';
        $params[] = $label;
    }
    if (array_key_exists('admin_user_id', $data)) {
        $adminId = (int) $data['admin_user_id'];
        $sets[] = 'admin_user_id=?';
        $params[] = $adminId > 0 ? $adminId : null;
    }
    if (array_key_exists('order_date', $data)) {
        $sets[] = 'order_date=?';
        $params[] = normalize_order_date($data['order_date']) ?: date('Y-m-d');
    }

    $sql = 'UPDATE order_items SET ' . implode(', ', $sets)
        . ' WHERE id=? AND row_type=\'site\'';
    $params[] = $itemId;
    if ($clientId > 0) {
        $sql .= ' AND client_id=?';
        $params[] = $clientId;
    }
    db()->prepare($sql)->execute($params);
    if ($clientId > 0) {
        db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    }
}

/**
 * @param array<int|string,string> $sites
 * @param array<int|string,string> $notes
 * @param array<int|string,string> $placements
 * @param array<int|string,string> $countries
 * @param array<int|string,mixed> $months
 * @param array<int|string,mixed> $endMonths
 * @param array<int|string,mixed> $years
 * @param array<int|string,mixed> $owner
 * @param array<int|string,mixed> $decided
 * @param array<int|string,string> $urls
 */
function save_order_sheet_rows(
    int $clientId,
    array $sites,
    array $notes,
    array $placements,
    array $countries,
    array $months,
    array $endMonths,
    array $years,
    array $owner,
    array $decided,
    array $urls,
    array $clientLabels = [],
    array $adminIds = [],
    array $dates = []
): int {
    ensure_order_schema();
    $saved = 0;
    foreach ($sites as $id => $siteName) {
        $itemId = (int) $id;
        if ($itemId <= 0) {
            continue;
        }
        $data = [
            'site_name' => $siteName,
            'site_note' => $notes[$id] ?? '',
            'placement_type' => $placements[$id] ?? '',
            'country' => $countries[$id] ?? '',
            'order_month' => $months[$id] ?? null,
            'period_end_month' => $endMonths[$id] ?? null,
            'order_year' => $years[$id] ?? date('Y'),
            'owner_price' => $owner[$id] ?? 0,
            'decided_price' => $decided[$id] ?? 0,
            'live_url' => $urls[$id] ?? '',
        ];
        if (array_key_exists($id, $clientLabels) || array_key_exists((string) $id, $clientLabels)) {
            $data['client_label'] = $clientLabels[$id] ?? '';
        }
        if (array_key_exists($id, $adminIds) || array_key_exists((string) $id, $adminIds)) {
            $data['admin_user_id'] = $adminIds[$id] ?? 0;
        }
        if (array_key_exists($id, $dates) || array_key_exists((string) $id, $dates)) {
            $data['order_date'] = $dates[$id] ?? '';
        }
        update_order_item($itemId, $clientId, $data);
        $saved++;
    }
    return $saved;
}

function delete_order_item(int $itemId, int $clientId = 0): void
{
    ensure_order_schema();
    if ($clientId > 0) {
        db()->prepare('DELETE FROM order_items WHERE id=? AND client_id=?')->execute([$itemId, $clientId]);
        db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
        return;
    }
    db()->prepare('DELETE FROM order_items WHERE id=?')->execute([$itemId]);
}

/**
 * Build display list with auto year-end banners when the year changes between site rows
 * (in addition to explicit year_end marker rows).
 *
 * @param list<array<string,mixed>> $items
 * @return list<array{kind:string,row?:array,from_year?:int,to_year?:int}>
 */
function order_sheet_display_rows(array $items): array
{
    $out = [];
    $prevYear = null;
    foreach ($items as $row) {
        $type = (string) ($row['row_type'] ?? 'site');
        $year = (int) ($row['order_year'] ?? 0);
        if ($type === 'year_end') {
            $out[] = [
                'kind' => 'year_end',
                'row' => $row,
                'from_year' => $year,
                'to_year' => $year + 1,
            ];
            $prevYear = $year + 1;
            continue;
        }
        if ($prevYear !== null && $year > 0 && $year !== $prevYear) {
            $out[] = [
                'kind' => 'year_end_auto',
                'from_year' => $prevYear,
                'to_year' => $year,
            ];
        }
        $out[] = ['kind' => 'site', 'row' => $row];
        if ($year > 0) {
            $prevYear = $year;
        }
    }
    return $out;
}

/**
 * Flat export rows for CSV / Excel (includes year-end marker lines).
 *
 * @return list<array{month:string,year:string,country:string,site:string,owner:string,decided:string,profit:string,live_url:string,status:string}>
 */
function order_sheet_export_rows(int $clientId): array
{
    $items = list_order_items($clientId);
    $display = order_sheet_display_rows($items);
    $out = [];
    foreach ($display as $block) {
        if ($block['kind'] === 'year_end' || $block['kind'] === 'year_end_auto') {
            $from = (int) ($block['from_year'] ?? 0);
            $to = (int) ($block['to_year'] ?? ($from + 1));
            $out[] = [
                'month' => 'YEAR END',
                'year' => (string) $from,
                'end_month' => '',
                'country' => '',
                'site' => 'Year ' . $from . ' ended · ' . $to . ' months started',
                'note' => '',
                'placement' => '',
                'owner' => '',
                'decided' => '',
                'profit' => '',
                'live_url' => '',
                'paid' => '',
                'status' => 'Year end',
            ];
            continue;
        }
        $row = $block['row'];
        $profit = order_profit($row['owner_price'], $row['decided_price']);
        $out[] = [
            'month' => order_month_label((int) ($row['order_month'] ?? 0)),
            'year' => (string) ((int) ($row['order_year'] ?: 0) ?: ''),
            'end_month' => order_month_label((int) ($row['period_end_month'] ?? 0)),
            'country' => (string) ($row['country'] ?? ''),
            'site' => (string) ($row['site_name'] ?? ''),
            'note' => (string) ($row['site_note'] ?? ''),
            'placement' => order_placement_label($row),
            'owner' => format_money($row['owner_price']),
            'decided' => format_money($row['decided_price']),
            'profit' => format_money($profit),
            'live_url' => (string) ($row['live_url'] ?? ''),
            'paid' => order_is_paid($row) ? 'Paid' : '',
            'status' => order_is_completed($row) ? 'Completed' : 'Processing',
        ];
    }
    return $out;
}

function order_sheet_download_filename(string $clientName, string $ext): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($clientName)) ?: 'client';
    $safe = trim($safe, '-') ?: 'client';
    return 'order-sheet-' . $safe . '-' . date('Y-m-d') . '.' . ltrim($ext, '.');
}

/** Stream CSV (Excel-friendly UTF-8 BOM). */
function order_sheet_download_csv(array $client, array $rows): void
{
    $filename = order_sheet_download_filename((string) $client['name'], 'csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    // BOM so Excel opens UTF-8 correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Client', (string) $client['name']]);
    fputcsv($out, ['Exported', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Site name', 'Note', 'Banner/Textlink', 'Country', 'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Start month', 'End month', 'Year', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['site'],
            $r['note'] ?? '',
            $r['placement'] ?? '',
            $r['country'],
            $r['owner'],
            $r['decided'],
            $r['live_url'],
            $r['paid'] ?? '',
            $r['profit'],
            $r['month'],
            $r['end_month'] ?? '',
            $r['year'],
            $r['status'],
        ]);
    }
    fclose($out);
}

/**
 * Stream an Excel-compatible .xls file (HTML table — opens in Excel / LibreOffice).
 * No external library required (Hostinger-safe).
 */
function order_sheet_download_xls(array $client, array $rows): void
{
    $filename = order_sheet_download_filename((string) $client['name'], 'xls');
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0">';
    echo '<tr><th colspan="13">Order sheet — ' . h((string) $client['name']) . '</th></tr>';
    echo '<tr><td colspan="13">Exported ' . h(date('Y-m-d H:i')) . '</td></tr>';
    echo '<tr>';
    foreach (['Site name', 'Note', 'Banner/Textlink', 'Country', 'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Start month', 'End month', 'Year', 'Status'] as $h) {
        echo '<th>' . h($h) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        $isYear = ($r['month'] === 'YEAR END');
        echo '<tr' . ($isYear ? ' style="background:#e8eaed;font-weight:bold"' : '') . '>';
        foreach (['site', 'note', 'placement', 'country', 'owner', 'decided', 'live_url', 'paid', 'profit', 'month', 'end_month', 'year', 'status'] as $key) {
            echo '<td>' . h((string) ($r[$key] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
}

function get_order_item(int $id): ?array
{
    ensure_order_schema();
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT i.*,
                u.full_name AS admin_full_name,
                u.username AS admin_username
         FROM order_items i
         LEFT JOIN users u ON u.id = i.admin_user_id
         WHERE i.id=? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Processing origin: wp (still in Website prices Processing), leftover (linked, WP left Processing),
 * manual (no Website prices link), or all. Omit / unknown → all so existing callers keep every Processing row.
 */
function normalize_order_pipeline_origin($origin): string
{
    $origin = strtolower(trim((string) $origin));
    return in_array($origin, ['wp', 'leftover', 'manual', 'all'], true) ? $origin : 'all';
}

/**
 * @param array{q?:string,country?:string,admin_id?:int,date_from?:string,date_to?:string,status?:string,folder?:string,origin?:string} $opts
 * @return array{0:string,1:list<mixed>}
 */
function order_pipeline_where_sql(array $opts = []): array
{
    $where = ["i.row_type = 'site'"];
    $params = [];
    $folder = strtolower(trim((string) ($opts['folder'] ?? '')));
    if ($folder === 'processing') {
        // Stay visible while order_stage is processing, even if Website prices left Processing.
        $where[] = "COALESCE(i.order_stage, 'processing') = 'processing'";
        $origin = normalize_order_pipeline_origin($opts['origin'] ?? 'all');
        if ($origin === 'wp') {
            $where[] = "i.site_price_row_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM site_price_rows owp
                WHERE owp.id = i.site_price_row_id AND owp.status_slug = 'processing'
            )";
        } elseif ($origin === 'leftover') {
            $where[] = "i.site_price_row_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM site_price_rows owp
                WHERE owp.id = i.site_price_row_id AND owp.status_slug = 'processing'
            )";
        } elseif ($origin === 'manual') {
            $where[] = 'i.site_price_row_id IS NULL';
        }
    } elseif ($folder === 'completed') {
        $where[] = "COALESCE(i.order_stage, 'processing') = 'completed'";
        $where[] = "TRIM(i.live_url) <> ''";
    }
    $q = trim((string) ($opts['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(i.site_name LIKE ? OR i.site_note LIKE ? OR i.country LIKE ?
            OR i.client_label LIKE ? OR i.live_url LIKE ?
            OR COALESCE(u.full_name, \'\') LIKE ? OR COALESCE(u.username, \'\') LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $country = trim((string) ($opts['country'] ?? ''));
    if ($country !== '') {
        $where[] = 'i.country = ?';
        $params[] = $country;
    }
    $adminId = (int) ($opts['admin_id'] ?? 0);
    if ($adminId > 0) {
        $where[] = 'i.admin_user_id = ?';
        $params[] = $adminId;
    }
    $dateFrom = normalize_order_date($opts['date_from'] ?? '');
    if ($dateFrom) {
        $where[] = 'i.order_date >= ?';
        $params[] = $dateFrom;
    }
    $dateTo = normalize_order_date($opts['date_to'] ?? '');
    if ($dateTo) {
        $where[] = 'i.order_date <= ?';
        $params[] = $dateTo;
    }
    $status = (string) ($opts['status'] ?? 'all');
    if ($folder === 'completed' || $folder === '') {
        if ($status === 'unpaid') {
            $where[] = "COALESCE(i.order_stage, 'processing') = 'completed' AND TRIM(i.live_url) <> '' AND COALESCE(i.is_paid, 0) = 0";
        } elseif ($status === 'completed' && $folder === '') {
            $where[] = "COALESCE(i.order_stage, 'processing') = 'completed' AND TRIM(i.live_url) <> ''";
        } elseif ($status === 'paid') {
            $where[] = 'COALESCE(i.is_paid, 0) = 1';
        } elseif ($status === 'open' || $status === 'processing') {
            $where[] = "COALESCE(i.order_stage, 'processing') = 'processing'";
        }
    }
    return [' WHERE ' . implode(' AND ', $where), $params];
}

function count_order_pipeline_rows(array $opts = []): int
{
    ensure_order_schema();
    if (($opts['folder'] ?? '') === 'processing') {
        order_reconcile_processing_from_website_prices();
    }
    [$whereSql, $params] = order_pipeline_where_sql($opts);
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM order_items i
         LEFT JOIN users u ON u.id = i.admin_user_id'
        . $whereSql
    );
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * @param array{q?:string,country?:string,admin_id?:int,date_from?:string,date_to?:string,status?:string,limit?:int,offset?:int} $opts
 * @return list<array<string,mixed>>
 */
function list_order_pipeline_rows(array $opts = []): array
{
    ensure_order_schema();
    if (($opts['folder'] ?? '') === 'processing') {
        order_reconcile_processing_from_website_prices();
    }
    $limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
    $offset = max(0, (int) ($opts['offset'] ?? 0));
    [$whereSql, $params] = order_pipeline_where_sql($opts);
    $sql = "SELECT i.*,
                u.full_name AS admin_full_name,
                u.username AS admin_username,
                spr.status_slug AS wp_status_slug,
                spr.country AS wp_country,
                spr.domain AS wp_domain
         FROM order_items i
         LEFT JOIN users u ON u.id = i.admin_user_id
         LEFT JOIN site_price_rows spr ON spr.id = i.site_price_row_id"
        . $whereSql
        . ' ORDER BY COALESCE(i.order_date, DATE(i.created_at)) DESC, i.id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return list<string> */
function list_order_pipeline_countries(): array
{
    ensure_order_schema();
    $rows = db()->query(
        "SELECT DISTINCT country FROM order_items
         WHERE row_type='site' AND TRIM(country) <> ''
         ORDER BY country ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * @param list<int> $ids
 * @return list<array<string,mixed>>
 */
function list_order_items_by_ids(array $ids): array
{
    ensure_order_schema();
    $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT i.*,
                u.full_name AS admin_full_name,
                u.username AS admin_username,
                spr.status_slug AS wp_status_slug,
                spr.country AS wp_country,
                spr.domain AS wp_domain
         FROM order_items i
         LEFT JOIN users u ON u.id = i.admin_user_id
         LEFT JOIN site_price_rows spr ON spr.id = i.site_price_row_id
         WHERE i.id IN ($placeholders) AND i.row_type='site'
         ORDER BY COALESCE(i.order_date, DATE(i.created_at)) DESC, i.id DESC"
    );
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/** @return list<int> */
function list_order_pipeline_ids(array $opts = [], int $limit = 0): array
{
    ensure_order_schema();
    if (($opts['folder'] ?? '') === 'processing') {
        order_reconcile_processing_from_website_prices();
    }
    $limit = max(0, $limit);
    [$whereSql, $params] = order_pipeline_where_sql($opts);
    $sql = 'SELECT i.id FROM order_items i
         LEFT JOIN users u ON u.id = i.admin_user_id'
        . $whereSql
        . ' ORDER BY COALESCE(i.order_date, DATE(i.created_at)) DESC, i.id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** Distinct client email/name values for the sheet typeahead. @return list<string> */
function list_order_pipeline_client_labels(): array
{
    ensure_order_schema();
    $rows = db()->query(
        "SELECT DISTINCT client_label FROM order_items
         WHERE row_type='site' AND TRIM(client_label) <> ''
         ORDER BY client_label ASC
         LIMIT 400"
    )->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $out[] = $name;
        }
    }
    return $out;
}

function order_invoice_push_id_cap(): int
{
    return 80;
}

/**
 * Header CTA for Completed: tick current-filter unpaid rows, or an honest label when the URL would be too long.
 *
 * @param list<int> $unpaidIds
 * @return array{href:string,label:string,class:string}
 */
function order_invoice_generate_push_cta(int $unpaidCount, array $unpaidIds): array
{
    $unpaidCount = max(0, $unpaidCount);
    if ($unpaidCount < 1) {
        return [
            'href' => 'index.php?page=admin_invoice_generate',
            'label' => 'Generate invoice',
            'class' => 'btn secondary',
        ];
    }
    $ids = [];
    foreach ($unpaidIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    $cap = order_invoice_push_id_cap();
    if ($unpaidCount <= $cap && count($ids) === $unpaidCount) {
        return [
            'href' => 'index.php?page=admin_invoice_generate&ids=' . implode(',', $ids),
            'label' => 'Push unpaid (' . $unpaidCount . ')',
            'class' => 'btn',
        ];
    }
    return [
        'href' => 'index.php?page=admin_invoice_generate',
        'label' => 'Open generate (' . $unpaidCount . ' unpaid, none ticked)',
        'class' => 'btn',
    ];
}

function order_wp_sheet_url(array $row): string
{
    $wpId = (int) ($row['site_price_row_id'] ?? 0);
    if ($wpId < 1 || !function_exists('site_price_sheet_url')) {
        return '';
    }
    $country = trim((string) ($row['wp_country'] ?? ''));
    if ($country === '') {
        $country = trim((string) ($row['country'] ?? ''));
    }
    if ($country === '') {
        return site_price_hub_url('admin_site_prices') . '&row=' . $wpId;
    }
    return site_price_sheet_url($country, 'admin_site_prices', ['row' => $wpId]);
}

function add_order_pipeline_row(?int $adminUserId, string $clientLabel = '', array $extra = []): int
{
    $adminId = (int) ($extra['admin_user_id'] ?? 0);
    if ($adminId < 1) {
        $adminId = (int) $adminUserId;
    }
    return add_order_item(null, '', null, null, [
        'admin_user_id' => $adminId,
        'client_label' => $clientLabel,
        'order_date' => date('Y-m-d'),
        'country' => (string) ($extra['country'] ?? ''),
    ]);
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,string>>
 */
function order_pipeline_export_rows(array $items): array
{
    $out = [];
    foreach ($items as $row) {
        if (($row['row_type'] ?? 'site') !== 'site') {
            continue;
        }
        $profit = order_profit($row['owner_price'], $row['decided_price']);
        $out[] = [
            'date' => (string) ($row['order_date'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'admin' => order_admin_display_name($row),
            'client' => (string) ($row['client_label'] ?? ''),
            'site' => (string) ($row['site_name'] ?? ''),
            'note' => (string) ($row['site_note'] ?? ''),
            'placement' => order_placement_label($row),
            'owner' => format_money($row['owner_price']),
            'decided' => format_money($row['decided_price']),
            'profit' => format_money($profit),
            'live_url' => (string) ($row['live_url'] ?? ''),
            'paid' => order_is_paid($row) ? 'Paid' : '',
            'month' => order_month_label((int) ($row['order_month'] ?? 0)),
            'end_month' => order_month_label((int) ($row['period_end_month'] ?? 0)),
            'year' => (string) ((int) ($row['order_year'] ?: 0) ?: ''),
            'status' => order_is_completed($row) ? 'Completed' : 'Processing',
        ];
    }
    return $out;
}

/**
 * Unique trimmed LIVE URLs, first-seen order.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<string>
 */
function order_live_urls_from_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $url = trim((string) ($row['live_url'] ?? ''));
        if ($url === '' || isset($out[$url])) {
            continue;
        }
        $out[$url] = $url;
    }
    return array_values($out);
}

/**
 * Unique trimmed site names, first-seen order.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<string>
 */
function order_site_names_from_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $site = trim((string) ($row['site_name'] ?? ''));
        if ($site === '' || isset($out[$site])) {
            continue;
        }
        $out[$site] = $site;
    }
    return array_values($out);
}

function order_pipeline_download_txt(array $urls): void
{
    $filename = 'order-live-urls-' . date('Y-m-d') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo implode("\n", $urls);
    if ($urls) {
        echo "\n";
    }
}

function order_pipeline_download_csv(array $rows): void
{
    $filename = 'order-sheet-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Exported', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, [
        'Country', 'Date', 'Admin', 'Client email or name', 'Site', 'Note', 'Banner/Textlink',
        'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Start month', 'End month', 'Year', 'Status',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['country'], $r['date'], $r['admin'], $r['client'], $r['site'], $r['note'] ?? '',
            $r['placement'] ?? '', $r['owner'], $r['decided'], $r['live_url'], $r['paid'] ?? '',
            $r['profit'], $r['month'], $r['end_month'] ?? '', $r['year'], $r['status'],
        ]);
    }
    fclose($out);
}

function order_pipeline_download_xls(array $rows): void
{
    $filename = 'order-sheet-' . date('Y-m-d') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $keys = ['country', 'date', 'admin', 'client', 'site', 'note', 'placement', 'owner', 'decided', 'live_url', 'paid', 'profit', 'month', 'end_month', 'year', 'status'];
    $heads = ['Country', 'Date', 'Admin', 'Client email or name', 'Site', 'Note', 'Banner/Textlink', 'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Start month', 'End month', 'Year', 'Status'];
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<table border="1" cellpadding="4" cellspacing="0">';
    echo '<tr><th colspan="' . count($heads) . '">Order management</th></tr>';
    echo '<tr><td colspan="' . count($heads) . '">Exported ' . h(date('Y-m-d H:i')) . '</td></tr>';
    echo '<tr>';
    foreach ($heads as $h) {
        echo '<th>' . h($h) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($keys as $key) {
            echo '<td>' . h((string) ($r[$key] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
}
