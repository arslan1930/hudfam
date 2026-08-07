<?php
/**
 * Admin Order Management — client sheets with site / country / month / prices / profit / live URL.
 */

function ensure_order_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_clients (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(200) NOT NULL,
          notes TEXT NULL,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_order_client_name (name),
          INDEX (created_by),
          CONSTRAINT fk_oc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          client_id INT NOT NULL,
          row_type ENUM('site','year_end') NOT NULL DEFAULT 'site',
          site_name VARCHAR(255) NOT NULL DEFAULT '',
          site_note VARCHAR(255) NOT NULL DEFAULT '',
          country VARCHAR(100) NOT NULL DEFAULT '',
          order_month TINYINT NULL,
          order_year SMALLINT NOT NULL DEFAULT 0,
          owner_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          decided_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          live_url VARCHAR(500) NOT NULL DEFAULT '',
          is_paid TINYINT(1) NOT NULL DEFAULT 0,
          sort_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (client_id, sort_order),
          INDEX (client_id, order_year, order_month),
          CONSTRAINT fk_oi_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE CASCADE
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
        if (!in_array('order_month', $cols, true)) {
            $alters[] = 'ADD COLUMN order_month TINYINT NULL AFTER country';
        }
        if (!in_array('order_year', $cols, true)) {
            $alters[] = 'ADD COLUMN order_year SMALLINT NOT NULL DEFAULT 0 AFTER order_month';
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
    } catch (Throwable $e) {
        // ignore on fresh installs
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

function order_profit($ownerPrice, $decidedPrice): float
{
    return round(parse_money($decidedPrice) - parse_money($ownerPrice), 2);
}

function order_is_completed(array $row): bool
{
    if (($row['row_type'] ?? 'site') === 'year_end') {
        return false;
    }
    return trim((string) ($row['live_url'] ?? '')) !== '';
}

function order_is_paid(array $row): bool
{
    if (($row['row_type'] ?? 'site') === 'year_end') {
        return false;
    }
    return (int) ($row['is_paid'] ?? 0) === 1;
}

function set_order_item_paid(int $itemId, int $clientId, bool $paid): void
{
    ensure_order_schema();
    db()->prepare(
        'UPDATE order_items SET is_paid=?, updated_at=NOW()
         WHERE id=? AND client_id=? AND row_type=\'site\''
    )->execute([$paid ? 1 : 0, $itemId, $clientId]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
}

/**
 * @return list<array<string,mixed>>
 */
function list_order_clients(): array
{
    ensure_order_schema();
    return db()->query(
        "SELECT c.*,
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
                  WHERE i.client_id = c.id AND i.row_type = 'site') AS total_profit
         FROM order_clients c
         ORDER BY c.name ASC"
    )->fetchAll();
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
    db()->prepare(
        'UPDATE order_clients SET name=?, notes=?, updated_at=NOW() WHERE id=?'
    )->execute([$name, trim($notes), $id]);
}

function delete_order_client(int $id): void
{
    ensure_order_schema();
    db()->prepare('DELETE FROM order_clients WHERE id=?')->execute([$id]);
}

function next_order_sort(int $clientId): int
{
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM order_items WHERE client_id=?');
    $max->execute([$clientId]);
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

function add_order_item(int $clientId, string $siteName = '', ?int $month = null, ?int $year = null): int
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
    $next = next_order_sort($clientId);
    db()->prepare(
        "INSERT INTO order_items
          (client_id, row_type, site_name, country, order_month, order_year, owner_price, decided_price, live_url, sort_order)
         VALUES (?, 'site', ?, '', ?, ?, 0, 0, '', ?)"
    )->execute([$clientId, trim($siteName), $month, $year, $next]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    return (int) db()->lastInsertId();
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
    // Seed first row of the new year so admin can keep filling
    add_order_item($clientId, '', 1, $endingYear + 1);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    return (int) db()->lastInsertId();
}

/**
 * @param array{site_name?:string,site_note?:string,country?:string,order_month?:mixed,order_year?:mixed,owner_price?:mixed,decided_price?:mixed,live_url?:string} $data
 */
function update_order_item(int $itemId, int $clientId, array $data): void
{
    ensure_order_schema();
    $month = (int) ($data['order_month'] ?? 0);
    if ($month < 1 || $month > 12) {
        $month = null;
    }
    $year = (int) ($data['order_year'] ?? 0);
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    $note = trim((string) ($data['site_note'] ?? ''));
    if (mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 255);
    }
    db()->prepare(
        'UPDATE order_items
         SET site_name=?, site_note=?, country=?, order_month=?, order_year=?,
             owner_price=?, decided_price=?, live_url=?, updated_at=NOW()
         WHERE id=? AND client_id=? AND row_type=\'site\''
    )->execute([
        trim((string) ($data['site_name'] ?? '')),
        $note,
        trim((string) ($data['country'] ?? '')),
        $month,
        $year,
        parse_money($data['owner_price'] ?? 0),
        parse_money($data['decided_price'] ?? 0),
        trim((string) ($data['live_url'] ?? '')),
        $itemId,
        $clientId,
    ]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
}

/**
 * @param array<int|string,string> $sites
 * @param array<int|string,string> $notes
 * @param array<int|string,string> $countries
 * @param array<int|string,mixed> $months
 * @param array<int|string,mixed> $years
 * @param array<int|string,mixed> $owner
 * @param array<int|string,mixed> $decided
 * @param array<int|string,string> $urls
 */
function save_order_sheet_rows(
    int $clientId,
    array $sites,
    array $notes,
    array $countries,
    array $months,
    array $years,
    array $owner,
    array $decided,
    array $urls
): int {
    ensure_order_schema();
    $saved = 0;
    foreach ($sites as $id => $siteName) {
        $itemId = (int) $id;
        if ($itemId <= 0) {
            continue;
        }
        update_order_item($itemId, $clientId, [
            'site_name' => $siteName,
            'site_note' => $notes[$id] ?? '',
            'country' => $countries[$id] ?? '',
            'order_month' => $months[$id] ?? null,
            'order_year' => $years[$id] ?? date('Y'),
            'owner_price' => $owner[$id] ?? 0,
            'decided_price' => $decided[$id] ?? 0,
            'live_url' => $urls[$id] ?? '',
        ]);
        $saved++;
    }
    return $saved;
}

function delete_order_item(int $itemId, int $clientId): void
{
    ensure_order_schema();
    db()->prepare('DELETE FROM order_items WHERE id=? AND client_id=?')->execute([$itemId, $clientId]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
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
                'country' => '',
                'site' => 'Year ' . $from . ' ended · ' . $to . ' months started',
                'note' => '',
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
            'country' => (string) ($row['country'] ?? ''),
            'site' => (string) ($row['site_name'] ?? ''),
            'note' => (string) ($row['site_note'] ?? ''),
            'owner' => format_money($row['owner_price']),
            'decided' => format_money($row['decided_price']),
            'profit' => format_money($profit),
            'live_url' => (string) ($row['live_url'] ?? ''),
            'paid' => order_is_paid($row) ? 'Paid' : '',
            'status' => order_is_completed($row) ? 'Completed' : 'Open',
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
    fputcsv($out, ['Site name', 'Country', 'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Month', 'Year', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['site'],
            $r['country'],
            $r['owner'],
            $r['decided'],
            $r['live_url'],
            $r['paid'] ?? '',
            $r['profit'],
            $r['month'],
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
    echo '<tr><th colspan="10">Order sheet — ' . h((string) $client['name']) . '</th></tr>';
    echo '<tr><td colspan="10">Exported ' . h(date('Y-m-d H:i')) . '</td></tr>';
    echo '<tr>';
    foreach (['Site name', 'Country', 'Owner price', 'Decided price', 'LIVE URL', 'Paid', 'Profit', 'Month', 'Year', 'Status'] as $h) {
        echo '<th>' . h($h) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        $isYear = ($r['month'] === 'YEAR END');
        echo '<tr' . ($isYear ? ' style="background:#e8eaed;font-weight:bold"' : '') . '>';
        foreach (['site', 'country', 'owner', 'decided', 'live_url', 'paid', 'profit', 'month', 'year', 'status'] as $key) {
            echo '<td>' . h((string) ($r[$key] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
}
