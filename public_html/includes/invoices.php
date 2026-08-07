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
          payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
          paid_at DATETIME NULL,
          is_manual TINYINT(1) NOT NULL DEFAULT 0,
          admin_note VARCHAR(255) NOT NULL DEFAULT '',
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_invoice_number (invoice_number),
          INDEX (client_id),
          INDEX (invoice_date),
          INDEX (payment_status),
          INDEX (is_manual),
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
          order_item_ids VARCHAR(500) NOT NULL DEFAULT '',
          sort_order INT NOT NULL DEFAULT 0,
          INDEX (invoice_id, sort_order),
          CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Migrate older invoice tables
    try {
        $invCols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
        $invAlters = [];
        if (!in_array('payment_status', $invCols, true)) {
            $invAlters[] = "ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER total_amount";
        }
        if (!in_array('paid_at', $invCols, true)) {
            $invAlters[] = 'ADD COLUMN paid_at DATETIME NULL AFTER payment_status';
        }
        if (!in_array('is_manual', $invCols, true)) {
            $invAlters[] = 'ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_at';
        }
        if (!in_array('admin_note', $invCols, true)) {
            $invAlters[] = "ADD COLUMN admin_note VARCHAR(255) NOT NULL DEFAULT '' AFTER is_manual";
        }
        if ($invAlters) {
            $pdo->exec('ALTER TABLE invoices ' . implode(', ', $invAlters));
        }
        try {
            $idx = $pdo->query("SHOW INDEX FROM invoices WHERE Key_name='is_manual'")->fetch();
            if (!$idx) {
                $pdo->exec('ALTER TABLE invoices ADD INDEX (is_manual)');
            }
        } catch (Throwable $e) {
            // ignore
        }
        $itemCols = $pdo->query('SHOW COLUMNS FROM invoice_items')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('order_item_ids', $itemCols, true)) {
            $pdo->exec(
                "ALTER TABLE invoice_items ADD COLUMN order_item_ids VARCHAR(500) NOT NULL DEFAULT '' AFTER line_total"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
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

function topurlz_logo_url(): string
{
    $file = dirname(__DIR__) . '/assets/img/topurlz-logo.svg';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=img/topurlz-logo.svg&v=' . rawurlencode($v));
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

function invoice_number_exists(string $number): bool
{
    ensure_invoice_schema();
    $number = trim($number);
    if ($number === '') {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM invoices WHERE invoice_number=? LIMIT 1');
    $stmt->execute([$number]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Always returns a new unique 8-digit invoice number (never reuses an existing one).
 */
function allocate_unique_invoice_number(): string
{
    ensure_invoice_schema();
    $candidate = next_invoice_number();
    // Skip any collisions (manual past numbers, races, non-sequential gaps).
    for ($i = 0; $i < 100; $i++) {
        if (!invoice_number_exists($candidate)) {
            return $candidate;
        }
        $candidate = str_pad((string) ((int) $candidate + 1), 8, '0', STR_PAD_LEFT);
    }
    throw new RuntimeException('Could not allocate a unique invoice number. Try again.');
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
 * Completed unpaid order rows (LIVE URL filled, not paid) for invoice picking.
 *
 * @return list<array<string,mixed>>
 */
function list_invoiceable_order_items(int $clientId): array
{
    ensure_order_schema();
    $stmt = db()->prepare(
        "SELECT * FROM order_items
         WHERE client_id=? AND row_type='site'
           AND TRIM(live_url) <> ''
           AND COALESCE(is_paid, 0) = 0
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

/**
 * @return list<int>
 */
function parse_order_item_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    if ($raw[0] === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('intval', $decoded), static fn ($id) => $id > 0));
        }
    }
    return array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw) ?: []), static fn ($id) => $id > 0));
}

/**
 * Build invoice line rows from selected order items.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{description:string,amount:float,qty:int,line_total:float,order_item_ids:list<int>}>
 */
function build_invoice_lines_from_orders(array $rows, bool $groupSameAmount): array
{
    if (!$rows) {
        return [];
    }

    $articles = [];
    $placements = [];
    foreach ($rows as $row) {
        if (order_is_placement($row)) {
            $placements[] = $row;
        } else {
            $articles[] = $row;
        }
    }

    $lines = [];

    // Banner / Textlink: one invoice line each (never “Article Published”).
    foreach ($placements as $row) {
        $amount = parse_money($row['decided_price'] ?? 0);
        $lines[] = [
            'description' => order_invoice_description($row),
            'amount' => $amount,
            'qty' => 1,
            'line_total' => $amount,
            'order_item_ids' => [(int) $row['id']],
        ];
    }

    if (!$groupSameAmount) {
        foreach ($articles as $row) {
            $amount = parse_money($row['decided_price'] ?? 0);
            $lines[] = [
                'description' => order_invoice_description($row),
                'amount' => $amount,
                'qty' => 1,
                'line_total' => $amount,
                'order_item_ids' => [(int) $row['id']],
            ];
        }
        return $lines;
    }

    // Group article rows by decided price (order preserved by first appearance).
    $groups = [];
    $order = [];
    foreach ($articles as $row) {
        $amount = parse_money($row['decided_price'] ?? 0);
        $key = number_format($amount, 2, '.', '');
        if (!isset($groups[$key])) {
            $groups[$key] = ['amount' => $amount, 'urls' => [], 'ids' => []];
            $order[] = $key;
        }
        $url = trim((string) ($row['live_url'] ?? ''));
        if ($url !== '') {
            $groups[$key]['urls'][] = $url;
            $groups[$key]['ids'][] = (int) $row['id'];
        }
    }

    foreach ($order as $key) {
        $g = $groups[$key];
        $urls = $g['urls'];
        $qty = count($urls);
        if ($qty === 0) {
            continue;
        }
        $desc = 'Article Published -' . "\n" . implode("\n", $urls);
        $lines[] = [
            'description' => $desc,
            'amount' => $g['amount'],
            'qty' => $qty,
            'line_total' => round($g['amount'] * $qty, 2),
            'order_item_ids' => $g['ids'],
        ];
    }
    return $lines;
}

function invoice_is_manual(array $invoice): bool
{
    return (int) ($invoice['is_manual'] ?? 0) === 1;
}

function invoice_admin_note(array $invoice): string
{
    return trim((string) ($invoice['admin_note'] ?? ''));
}

/**
 * Create an empty blank invoice in the normal bill format (no order-sheet link).
 */
function create_blank_invoice(?int $createdBy): int
{
    $company = invoice_company_defaults();
    return create_invoice([
        'is_manual' => 1,
        'invoice_date' => date('Y-m-d'),
        'admin_note' => '',
        'bill_to_name' => '',
        'bill_to_address' => '',
        'bill_to_hrb' => '',
        'bill_to_vat' => '',
        'supplier_number' => 'NEW',
        'cost_center' => '',
        'orderer' => '',
        'company_name' => $company['company_name'],
        'company_bic' => $company['company_bic'],
        'company_iban' => $company['company_iban'],
        'company_phone' => $company['company_phone'],
        'company_address' => $company['company_address'],
        'company_reg_no' => $company['company_reg_no'],
        'vat_note' => $company['vat_note'],
    ], [], $createdBy);
}

/**
 * Update a blank invoice header + replace line items. Not linked to order sheets.
 *
 * @param array<string,mixed> $header
 * @param list<array{description:string,amount:float|string,qty:int|string}> $lines
 */
function update_blank_invoice(int $invoiceId, array $header, array $lines): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (!invoice_is_manual($invoice)) {
        throw new InvalidArgumentException('Only blank invoices can be edited this way.');
    }
    if (invoice_is_paid($invoice)) {
        throw new InvalidArgumentException('Paid invoices cannot be edited. Unmark is not available — create a new blank invoice if needed.');
    }

    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = (string) $invoice['invoice_date'];
    }
    $adminNote = trim((string) ($header['admin_note'] ?? ''));
    if (mb_strlen($adminNote) > 255) {
        $adminNote = mb_substr($adminNote, 0, 255);
    }

    $company = invoice_company_defaults();
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
        $lineTotal = round($amount * $qty, 2);
        $normalized[] = [
            'description' => $desc,
            'amount' => $amount,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'sort_order' => $sort++,
        ];
        $total += $lineTotal;
    }

    $billName = trim((string) ($header['bill_to_name'] ?? ''));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE invoices SET
                invoice_date=?, admin_note=?,
                client_name=?, bill_to_name=?, bill_to_address=?, bill_to_hrb=?, bill_to_vat=?,
                supplier_number=?, cost_center=?, orderer=?,
                company_name=?, company_bic=?, company_iban=?, company_phone=?,
                company_address=?, company_reg_no=?, vat_note=?,
                total_amount=?, updated_at=NOW()
             WHERE id=? AND is_manual=1'
        )->execute([
            $invoiceDate,
            $adminNote,
            $billName,
            $billName,
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
            round($total, 2),
            $invoiceId,
        ]);

        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, amount, qty, line_total, order_item_ids, sort_order)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($normalized as $line) {
            $itemStmt->execute([
                $invoiceId,
                $line['description'],
                $line['amount'],
                $line['qty'],
                $line['line_total'],
                '',
                $line['sort_order'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string,mixed> $header
 * @param list<array{description:string,amount:float|string,qty:int|string,line_total?:float|string}> $lines
 */
function create_invoice(array $header, array $lines, ?int $createdBy): int
{
    ensure_invoice_schema();
    $isManual = !empty($header['is_manual']);
    // Blank invoices may start with zero line items (filled later on the invoice).
    if (!$lines && !$isManual) {
        throw new InvalidArgumentException('Select at least one completed article to invoice.');
    }

    $company = invoice_company_defaults();
    // Invoice number is allocated inside the insert retry loop (never from POST).
    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = date('Y-m-d');
    }
    $adminNote = trim((string) ($header['admin_note'] ?? ''));
    if (mb_strlen($adminNote) > 255) {
        $adminNote = mb_substr($adminNote, 0, 255);
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
            'order_item_ids' => $isManual
                ? []
                : array_values(array_filter(array_map('intval', (array) ($line['order_item_ids'] ?? [])), static fn ($id) => $id > 0)),
            'sort_order' => $sort++,
        ];
        $total += $lineTotal;
    }
    if (!$normalized && !$isManual) {
        throw new InvalidArgumentException('Select at least one unpaid completed article to invoice.');
    }

    // Blank invoices are never linked to order-management clients / sheet rows.
    $clientId = $isManual ? null : (int) ($header['client_id'] ?? 0);
    if ($clientId !== null && $clientId <= 0) {
        $clientId = null;
    }

    // Guard: never invoice already-paid order rows (sheet invoices only)
    if (!$isManual && $clientId) {
        foreach ($normalized as $line) {
            foreach ($line['order_item_ids'] as $oid) {
                $chk = db()->prepare(
                    "SELECT is_paid, live_url FROM order_items
                     WHERE id=? AND client_id=? AND row_type='site' LIMIT 1"
                );
                $chk->execute([$oid, $clientId]);
                $row = $chk->fetch();
                if (!$row) {
                    throw new InvalidArgumentException('One of the selected sheet rows was not found.');
                }
                if ((int) ($row['is_paid'] ?? 0) === 1) {
                    throw new InvalidArgumentException('Paid rows cannot be added to an invoice. Unmark paid first, or pick unpaid rows only.');
                }
                if (trim((string) ($row['live_url'] ?? '')) === '') {
                    throw new InvalidArgumentException('Only rows with a LIVE URL can be invoiced.');
                }
            }
        }
    }

    $pdo = db();
    $maxAttempts = 8;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        // Re-allocate each attempt so concurrent creates never collide.
        $invoiceNumber = allocate_unique_invoice_number();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices (
                    invoice_number, invoice_date, client_id, client_name,
                    bill_to_name, bill_to_address, bill_to_hrb, bill_to_vat,
                    supplier_number, cost_center, orderer,
                    company_name, company_bic, company_iban, company_phone,
                    company_address, company_reg_no, vat_note,
                    currency, total_amount, payment_status, is_manual, admin_note, created_by
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $invoiceNumber,
                $invoiceDate,
                $clientId,
                $isManual ? trim((string) ($header['bill_to_name'] ?? ($header['client_name'] ?? ''))) : trim((string) ($header['client_name'] ?? '')),
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
                'unpaid',
                $isManual ? 1 : 0,
                $adminNote,
                $createdBy,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, description, amount, qty, line_total, order_item_ids, sort_order)
                 VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($normalized as $line) {
                $itemStmt->execute([
                    $invoiceId,
                    $line['description'],
                    $line['amount'],
                    $line['qty'],
                    $line['line_total'],
                    implode(',', $line['order_item_ids']),
                    $line['sort_order'],
                ]);
            }

            if (!$isManual && $clientId) {
                save_invoice_client_profile($clientId, $header);
            }

            $pdo->commit();
            return $invoiceId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $lastError = $e;
            $message = strtolower($e->getMessage());
            $isDuplicateNumber = str_contains($message, 'invoice_number')
                || str_contains($message, 'duplicate')
                || (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062);
            if (!$isDuplicateNumber || $attempt === $maxAttempts) {
                throw $e;
            }
        }
    }

    throw $lastError ?? new RuntimeException('Could not allocate a unique invoice number.');
}

/**
 * Mark invoice payment received. Sheet invoices also mark linked order rows paid;
 * Blank (manual) invoices only update the invoice (not linked to order management).
 */
function mark_invoice_payment_received(int $invoiceId): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (($invoice['payment_status'] ?? 'unpaid') === 'paid') {
        return;
    }

    $isManual = invoice_is_manual($invoice);
    $clientId = (int) ($invoice['client_id'] ?? 0);
    $items = list_invoice_items($invoiceId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$isManual) {
            foreach ($items as $item) {
                foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
                    if ($clientId > 0) {
                        set_order_item_paid($oid, $clientId, true);
                    }
                }
            }
        }
        $pdo->prepare(
            "UPDATE invoices SET payment_status='paid', paid_at=NOW(), updated_at=NOW() WHERE id=?"
        )->execute([$invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function invoice_is_paid(array $invoice): bool
{
    return ($invoice['payment_status'] ?? 'unpaid') === 'paid';
}

function delete_invoice(int $id): void
{
    ensure_invoice_schema();
    db()->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
}
