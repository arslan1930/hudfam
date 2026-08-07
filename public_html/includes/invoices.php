<?php
/**
 * Admin Invoices — generate printable invoices from completed order-sheet rows.
 */

function ensure_invoice_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_order_schema();
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invoice_client_profiles (
          client_id INT NOT NULL PRIMARY KEY,
          bill_to_name VARCHAR(200) NOT NULL DEFAULT '',
          bill_to_address TEXT NULL,
          bill_to_hrb VARCHAR(120) NOT NULL DEFAULT '',
          bill_to_vat VARCHAR(120) NOT NULL DEFAULT '',
          supplier_number VARCHAR(120) NOT NULL DEFAULT 'NEW',
          cost_center VARCHAR(200) NOT NULL DEFAULT '',
          orderer VARCHAR(200) NOT NULL DEFAULT '',
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_icp_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invoices (
          id INT AUTO_INCREMENT PRIMARY KEY,
          invoice_number VARCHAR(32) NOT NULL,
          invoice_date DATE NOT NULL,
          client_id INT NULL,
          client_name VARCHAR(200) NOT NULL DEFAULT '',
          bill_to_name VARCHAR(200) NOT NULL DEFAULT '',
          bill_to_address TEXT NULL,
          bill_to_hrb VARCHAR(120) NOT NULL DEFAULT '',
          bill_to_vat VARCHAR(120) NOT NULL DEFAULT '',
          supplier_number VARCHAR(120) NOT NULL DEFAULT 'NEW',
          cost_center VARCHAR(200) NOT NULL DEFAULT '',
          orderer VARCHAR(200) NOT NULL DEFAULT '',
          company_name VARCHAR(200) NOT NULL DEFAULT '',
          company_bic VARCHAR(80) NOT NULL DEFAULT '',
          company_iban VARCHAR(80) NOT NULL DEFAULT '',
          company_phone VARCHAR(80) NOT NULL DEFAULT '',
          company_address TEXT NULL,
          company_reg_no VARCHAR(80) NOT NULL DEFAULT '',
          vat_note VARCHAR(255) NOT NULL DEFAULT 'Not VAT registered – no VAT charged.',
          currency CHAR(3) NOT NULL DEFAULT 'EUR',
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_invoice_number (invoice_number),
          INDEX (client_id),
          INDEX (invoice_date),
          CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE SET NULL,
          CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invoice_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          invoice_id INT NOT NULL,
          description TEXT NOT NULL,
          amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          qty INT NOT NULL DEFAULT 1,
          line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          sort_order INT NOT NULL DEFAULT 0,
          INDEX (invoice_id, sort_order),
          CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Default supplier / bank block (matches sample invoice). */
function invoice_company_defaults(): array
{
    return [
        'company_name' => 'Topurlz Ltd',
        'company_bic' => 'TRWIBEB1XXX',
        'company_iban' => 'BE04905543949331',
        'company_phone' => '+447445152374',
        'company_address' => '20 Wenlock Road, London, England, N1 7GU',
        'company_reg_no' => '16607074',
        'vat_note' => 'Not VAT registered – no VAT charged.',
    ];
}

function format_euro($value): string
{
    return '€' . number_format(parse_money($value), 2, '.', ',');
}

function format_invoice_date(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return date('d/m/Y', $ts);
}

function next_invoice_number(): string
{
    ensure_invoice_schema();
    $max = (int) db()->query(
        "SELECT COALESCE(MAX(CAST(invoice_number AS UNSIGNED)), 134854)
         FROM invoices
         WHERE invoice_number REGEXP '^[0-9]+$'"
    )->fetchColumn();
    return str_pad((string) ($max + 1), 8, '0', STR_PAD_LEFT);
}

/**
 * @return list<array<string,mixed>>
 */
function list_invoices(): array
{
    ensure_invoice_schema();
    return db()->query(
        "SELECT i.*,
                (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count
         FROM invoices i
         ORDER BY i.invoice_date DESC, i.id DESC"
    )->fetchAll();
}

function get_invoice(int $id): ?array
{
    ensure_invoice_schema();
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM invoices WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function list_invoice_items(int $invoiceId): array
{
    ensure_invoice_schema();
    $stmt = db()->prepare(
        'SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

function get_invoice_client_profile(int $clientId): ?array
{
    ensure_invoice_schema();
    if ($clientId <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM invoice_client_profiles WHERE client_id=? LIMIT 1');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function save_invoice_client_profile(int $clientId, array $data): void
{
    ensure_invoice_schema();
    db()->prepare(
        'INSERT INTO invoice_client_profiles
          (client_id, bill_to_name, bill_to_address, bill_to_hrb, bill_to_vat,
           supplier_number, cost_center, orderer)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           bill_to_name=VALUES(bill_to_name),
           bill_to_address=VALUES(bill_to_address),
           bill_to_hrb=VALUES(bill_to_hrb),
           bill_to_vat=VALUES(bill_to_vat),
           supplier_number=VALUES(supplier_number),
           cost_center=VALUES(cost_center),
           orderer=VALUES(orderer)'
    )->execute([
        $clientId,
        trim((string) ($data['bill_to_name'] ?? '')),
        trim((string) ($data['bill_to_address'] ?? '')),
        trim((string) ($data['bill_to_hrb'] ?? '')),
        trim((string) ($data['bill_to_vat'] ?? '')),
        trim((string) ($data['supplier_number'] ?? 'NEW')) ?: 'NEW',
        trim((string) ($data['cost_center'] ?? '')),
        trim((string) ($data['orderer'] ?? '')),
    ]);
}

/**
 * Completed order rows (LIVE URL filled) for invoice line picking.
 *
 * @return list<array<string,mixed>>
 */
function list_invoiceable_order_items(int $clientId): array
{
    ensure_order_schema();
    $stmt = db()->prepare(
        "SELECT * FROM order_items
         WHERE client_id=? AND row_type='site' AND TRIM(live_url) <> ''
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

/**
 * Build invoice line rows from selected order items.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{description:string,amount:float,qty:int,line_total:float}>
 */
function build_invoice_lines_from_orders(array $rows, bool $groupSameAmount): array
{
    if (!$rows) {
        return [];
    }

    if (!$groupSameAmount) {
        $lines = [];
        foreach ($rows as $row) {
            $url = trim((string) ($row['live_url'] ?? ''));
            $amount = parse_money($row['decided_price'] ?? 0);
            $lines[] = [
                'description' => 'Article Published -' . "\n" . $url,
                'amount' => $amount,
                'qty' => 1,
                'line_total' => $amount,
            ];
        }
        return $lines;
    }

    // Group by decided price (order preserved by first appearance).
    $groups = [];
    $order = [];
    foreach ($rows as $row) {
        $amount = parse_money($row['decided_price'] ?? 0);
        $key = number_format($amount, 2, '.', '');
        if (!isset($groups[$key])) {
            $groups[$key] = ['amount' => $amount, 'urls' => []];
            $order[] = $key;
        }
        $url = trim((string) ($row['live_url'] ?? ''));
        if ($url !== '') {
            $groups[$key]['urls'][] = $url;
        }
    }

    $lines = [];
    foreach ($order as $key) {
        $g = $groups[$key];
        $urls = $g['urls'];
        $qty = count($urls);
        if ($qty === 0) {
            continue;
        }
        // Sample layout: "Article Published -" once, then each live URL on its own line.
        $desc = 'Article Published -' . "\n" . implode("\n", $urls);
        $lines[] = [
            'description' => $desc,
            'amount' => $g['amount'],
            'qty' => $qty,
            'line_total' => round($g['amount'] * $qty, 2),
        ];
    }
    return $lines;
}

/**
 * @param array<string,mixed> $header
 * @param list<array{description:string,amount:float|string,qty:int|string,line_total?:float|string}> $lines
 */
function create_invoice(array $header, array $lines, ?int $createdBy): int
{
    ensure_invoice_schema();
    if (!$lines) {
        throw new InvalidArgumentException('Select at least one completed article to invoice.');
    }

    $company = invoice_company_defaults();
    $invoiceNumber = trim((string) ($header['invoice_number'] ?? ''));
    if ($invoiceNumber === '') {
        $invoiceNumber = next_invoice_number();
    }
    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = date('Y-m-d');
    }

    $normalized = [];
    $total = 0.0;
    $sort = 0;
    foreach ($lines as $line) {
        $desc = trim((string) ($line['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $amount = parse_money($line['amount'] ?? 0);
        $qty = max(1, (int) ($line['qty'] ?? 1));
        $lineTotal = isset($line['line_total'])
            ? parse_money($line['line_total'])
            : round($amount * $qty, 2);
        $normalized[] = [
            'description' => $desc,
            'amount' => $amount,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'sort_order' => $sort++,
        ];
        $total += $lineTotal;
    }
    if (!$normalized) {
        throw new InvalidArgumentException('Select at least one completed article to invoice.');
    }

    $clientId = (int) ($header['client_id'] ?? 0);
    if ($clientId <= 0) {
        $clientId = null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO invoices (
                invoice_number, invoice_date, client_id, client_name,
                bill_to_name, bill_to_address, bill_to_hrb, bill_to_vat,
                supplier_number, cost_center, orderer,
                company_name, company_bic, company_iban, company_phone,
                company_address, company_reg_no, vat_note,
                currency, total_amount, created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $invoiceNumber,
            $invoiceDate,
            $clientId,
            trim((string) ($header['client_name'] ?? '')),
            trim((string) ($header['bill_to_name'] ?? '')),
            trim((string) ($header['bill_to_address'] ?? '')),
            trim((string) ($header['bill_to_hrb'] ?? '')),
            trim((string) ($header['bill_to_vat'] ?? '')),
            trim((string) ($header['supplier_number'] ?? 'NEW')) ?: 'NEW',
            trim((string) ($header['cost_center'] ?? '')),
            trim((string) ($header['orderer'] ?? '')),
            trim((string) ($header['company_name'] ?? $company['company_name'])),
            trim((string) ($header['company_bic'] ?? $company['company_bic'])),
            trim((string) ($header['company_iban'] ?? $company['company_iban'])),
            trim((string) ($header['company_phone'] ?? $company['company_phone'])),
            trim((string) ($header['company_address'] ?? $company['company_address'])),
            trim((string) ($header['company_reg_no'] ?? $company['company_reg_no'])),
            trim((string) ($header['vat_note'] ?? $company['vat_note'])),
            'EUR',
            round($total, 2),
            $createdBy,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, amount, qty, line_total, sort_order)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($normalized as $line) {
            $itemStmt->execute([
                $invoiceId,
                $line['description'],
                $line['amount'],
                $line['qty'],
                $line['line_total'],
                $line['sort_order'],
            ]);
        }

        if ($clientId) {
            save_invoice_client_profile($clientId, $header);
        }

        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_invoice(int $id): void
{
    ensure_invoice_schema();
    db()->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
}
