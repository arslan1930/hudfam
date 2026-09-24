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
    if (function_exists('txf_schema_is_current') && txf_schema_is_current(__FUNCTION__, __FILE__)) {
        ensure_order_schema();
        invoice_ensure_events_table();
        return;
    }
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
          company_logo VARCHAR(255) NOT NULL DEFAULT '',
          vat_note VARCHAR(255) NOT NULL DEFAULT 'Not VAT registered – no VAT charged.',
          currency CHAR(3) NOT NULL DEFAULT 'EUR',
          total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
          paid_at DATETIME NULL,
          is_manual TINYINT(1) NOT NULL DEFAULT 0,
          work_status VARCHAR(20) NOT NULL DEFAULT 'done',
          admin_note VARCHAR(255) NOT NULL DEFAULT '',
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_invoice_number (invoice_number),
          INDEX (client_id),
          INDEX (invoice_date),
          INDEX (payment_status),
          INDEX (is_manual),
          INDEX (work_status),
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
          order_item_ids TEXT NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          INDEX (invoice_id, sort_order),
          CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    invoice_ensure_events_table();

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
        if (!in_array('work_status', $invCols, true)) {
            $invAlters[] = "ADD COLUMN work_status VARCHAR(20) NOT NULL DEFAULT 'done' AFTER is_manual";
        }
        if (!in_array('admin_note', $invCols, true)) {
            $invAlters[] = "ADD COLUMN admin_note VARCHAR(255) NOT NULL DEFAULT '' AFTER work_status";
        }
        if (!in_array('company_logo', $invCols, true)) {
            $invAlters[] = "ADD COLUMN company_logo VARCHAR(255) NOT NULL DEFAULT '' AFTER company_reg_no";
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
        try {
            $idx = $pdo->query("SHOW INDEX FROM invoices WHERE Key_name='work_status'")->fetch();
            if (!$idx) {
                $pdo->exec('ALTER TABLE invoices ADD INDEX (work_status)');
            }
        } catch (Throwable $e) {
            // ignore
        }
        // Existing empty blank invoices start as drafts (still need data).
        try {
            $pdo->exec(
                "UPDATE invoices SET work_status='draft'
                 WHERE is_manual=1 AND payment_status<>'paid'
                   AND total_amount<=0 AND (work_status='' OR work_status='done')"
            );
        } catch (Throwable $e) {
            // ignore
        }
        $itemCols = $pdo->query('SHOW COLUMNS FROM invoice_items')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('order_item_ids', $itemCols, true)) {
            $pdo->exec(
                "ALTER TABLE invoice_items ADD COLUMN order_item_ids TEXT NOT NULL AFTER line_total"
            );
        } else {
            $idCol = $pdo->query("SHOW COLUMNS FROM invoice_items LIKE 'order_item_ids'")->fetch(PDO::FETCH_ASSOC);
            $idType = strtolower((string) ($idCol['Type'] ?? ''));
            // A grouped line stores every order id. VARCHAR(500) rejects a long list
            // and the insert fails, so the push never lands on the invoice.
            if ($idType !== '' && !str_contains($idType, 'text')) {
                $pdo->exec('ALTER TABLE invoice_items MODIFY order_item_ids TEXT NOT NULL');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if (function_exists('txf_schema_mark_current')) {
        txf_schema_mark_current(__FUNCTION__);
    }
}

function invoice_ensure_events_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $sql = "CREATE TABLE IF NOT EXISTS invoice_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          invoice_id INT NULL,
          event_type VARCHAR(40) NOT NULL DEFAULT '',
          actor_user_id INT NULL,
          summary VARCHAR(500) NOT NULL DEFAULT '',
          payload TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_ie_invoice (invoice_id, id),
          INDEX idx_ie_type (event_type)";
    try {
        db()->exec(
            $sql . ",
          CONSTRAINT fk_ie_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
          CONSTRAINT fk_ie_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        try {
            db()->exec($sql . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (Throwable $e2) {
            // History is optional — billing pages must still load.
        }
    }
}

/** Default supplier / bank block (matches sample invoice). */
function invoice_company_defaults(): array
{
    return [
        'company_name' => 'Teqno Ltd',
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
    // Prefer Teqno Ltd branding when present; fall back to legacy Topurlz assets.
    $teqnoPng = dirname(__DIR__) . '/assets/img/teqno-logo.png';
    if (is_file($teqnoPng)) {
        $v = (string) filemtime($teqnoPng);
        return app_url('asset.php?f=img/teqno-logo.png&v=' . rawurlencode($v));
    }
    $teqnoSvg = dirname(__DIR__) . '/assets/img/teqno-logo.svg';
    if (is_file($teqnoSvg)) {
        $v = (string) filemtime($teqnoSvg);
        return app_url('asset.php?f=img/teqno-logo.svg&v=' . rawurlencode($v));
    }
    $png = dirname(__DIR__) . '/assets/img/topurlz-logo.png';
    if (is_file($png)) {
        $v = (string) filemtime($png);
        return app_url('asset.php?f=img/topurlz-logo.png&v=' . rawurlencode($v));
    }
    $file = dirname(__DIR__) . '/assets/img/topurlz-logo.svg';
    $v = is_file($file) ? (string) filemtime($file) : (string) time();
    return app_url('asset.php?f=img/topurlz-logo.svg&v=' . rawurlencode($v));
}

/** Absolute directory for per-invoice logo uploads. */
function invoice_logo_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/invoice_logos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return $dir;
}

function invoice_logo_filename_valid(string $name): bool
{
    return (bool) preg_match('/^inv_\d+_[a-zA-Z0-9]{6,32}\.(png|jpe?g|webp|gif)$/', $name);
}

function invoice_logo_belongs_to_invoice(string $name, int $invoiceId): bool
{
    $name = basename(trim($name));
    if ($invoiceId < 1 || !invoice_logo_filename_valid($name)) {
        return false;
    }
    return str_starts_with($name, 'inv_' . $invoiceId . '_');
}

/**
 * Public URL for the logo printed on this invoice (custom upload or default).
 *
 * @param array<string,mixed> $invoice
 */
function invoice_logo_url(array $invoice): string
{
    $name = basename(trim((string) ($invoice['company_logo'] ?? '')));
    $invoiceId = (int) ($invoice['id'] ?? 0);
    if ($name !== '' && ($invoiceId < 1 || invoice_logo_belongs_to_invoice($name, $invoiceId))) {
        $path = invoice_logo_storage_dir() . '/' . $name;
        if (is_file($path)) {
            $v = (string) filemtime($path);
            return app_url('invoice_logo.php?f=' . rawurlencode($name) . '&v=' . rawurlencode($v));
        }
    }
    return topurlz_logo_url();
}

function invoice_logo_has_custom(array $invoice): bool
{
    $name = basename(trim((string) ($invoice['company_logo'] ?? '')));
    $invoiceId = (int) ($invoice['id'] ?? 0);
    return $name !== ''
        && ($invoiceId < 1 || invoice_logo_belongs_to_invoice($name, $invoiceId))
        && is_file(invoice_logo_storage_dir() . '/' . $name);
}

function invoice_delete_logo_file(string $name): void
{
    $name = basename(trim($name));
    if ($name === '' || !invoice_logo_filename_valid($name)) {
        return;
    }
    $path = invoice_logo_storage_dir() . '/' . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Plan a logo change without deleting the previous file yet.
 * Call invoice_finalize_logo_cleanup() only after the invoice row saves successfully.
 *
 * Prefers a real multipart upload; falls back to a data-URI from the browser
 * (some hosts/browsers drop file inputs when Save is outside the form).
 *
 * @param array<string,mixed>|null $uploaded $_FILES['company_logo'] entry
 * @return array{value:string, delete_after:?string}
 */
function invoice_resolve_logo_for_save(
    array $invoice,
    ?array $uploaded,
    bool $resetToDefault,
    string $dataUri = ''
): array {
    $invoiceId = (int) ($invoice['id'] ?? 0);
    $current = basename(trim((string) ($invoice['company_logo'] ?? '')));
    if ($current !== '' && $invoiceId > 0 && !invoice_logo_belongs_to_invoice($current, $invoiceId)) {
        $current = '';
    } elseif ($current !== '' && !invoice_logo_filename_valid($current)) {
        $current = '';
    }

    if ($resetToDefault) {
        return [
            'value' => '',
            'delete_after' => $current !== '' ? $current : null,
        ];
    }

    $err = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    $dataUri = trim($dataUri);

    // Prefer a successful multipart file. On Hostinger, file uploads often fail
    // (size limits / dropped inputs) while the browser data-URI backup still works.
    if ($err === UPLOAD_ERR_OK && $uploaded !== null) {
        try {
            $tmp = (string) ($uploaded['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('Logo upload failed. Try again.');
            }
            $size = (int) ($uploaded['size'] ?? 0);
            if ($size < 1 || $size > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('Logo must be an image under 2 MB.');
            }
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new InvalidArgumentException('Logo must be a PNG, JPG, WEBP, or GIF image.');
            }
            $mime = strtolower((string) ($info['mime'] ?? ''));
            $ext = invoice_logo_ext_for_mime($mime);
            if ($ext === '') {
                throw new InvalidArgumentException('Logo must be a PNG, JPG, WEBP, or GIF image.');
            }
            return invoice_store_logo_bytes(
                $invoiceId,
                (string) file_get_contents($tmp),
                $ext,
                $current
            );
        } catch (InvalidArgumentException $uploadEx) {
            if ($dataUri === '') {
                throw $uploadEx;
            }
            // Fall through to data-URI backup.
        }
    }

    if ($dataUri !== '') {
        return invoice_store_logo_data_uri($invoiceId, $dataUri, $current);
    }

    if ($err !== UPLOAD_ERR_NO_FILE && $uploaded !== null) {
        $why = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Logo is too large for this server. Use a smaller PNG or JPG under 1 MB.',
            UPLOAD_ERR_PARTIAL => 'Logo upload was interrupted. Try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server could not store the upload. Ask hosting to fix PHP temp uploads.',
            default => 'Logo upload failed. Try a smaller PNG or JPG (under 2 MB).',
        };
        throw new InvalidArgumentException($why);
    }

    return [
        'value' => $current,
        'delete_after' => null,
    ];
}

function invoice_logo_ext_for_mime(string $mime): string
{
    $map = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    return $map[strtolower(trim($mime))] ?? '';
}

/**
 * @return array{value:string, delete_after:?string}
 */
function invoice_store_logo_bytes(int $invoiceId, string $bytes, string $ext, string $current): array
{
    if ($invoiceId < 1) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    $len = strlen($bytes);
    if ($len < 32 || $len > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('Logo must be an image under 2 MB.');
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false) {
        throw new InvalidArgumentException('Logo must be a PNG, JPG, WEBP, or GIF image.');
    }
    $extFromMime = invoice_logo_ext_for_mime(strtolower((string) ($info['mime'] ?? '')));
    if ($extFromMime === '') {
        throw new InvalidArgumentException('Logo must be a PNG, JPG, WEBP, or GIF image.');
    }
    $ext = $extFromMime;
    $name = 'inv_' . $invoiceId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir = invoice_logo_storage_dir();
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }
    if (!is_dir($destDir) || !is_writable($destDir)) {
        throw new InvalidArgumentException(
            'Logo folder is not writable on the server. Create public_html/uploads/invoice_logos and make it writable.'
        );
    }
    $dest = $destDir . '/' . $name;
    if (@file_put_contents($dest, $bytes) === false) {
        throw new InvalidArgumentException('Could not save the logo file.');
    }
    @chmod($dest, 0644);
    return [
        'value' => $name,
        'delete_after' => ($current !== '' && $current !== $name) ? $current : null,
    ];
}

/**
 * @return array{value:string, delete_after:?string}
 */
function invoice_store_logo_data_uri(int $invoiceId, string $dataUri, string $current): array
{
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,#i', $dataUri, $m)) {
        throw new InvalidArgumentException('Logo must be a PNG, JPG, WEBP, or GIF image.');
    }
    $comma = strpos($dataUri, ',');
    if ($comma === false) {
        throw new InvalidArgumentException('Logo upload failed. Try again.');
    }
    $raw = base64_decode(substr($dataUri, $comma + 1), true);
    if ($raw === false) {
        throw new InvalidArgumentException('Logo upload failed. Try again.');
    }
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    return invoice_store_logo_bytes($invoiceId, $raw, $ext, $current);
}

/** Delete the previous logo file after a successful invoice save. */
function invoice_finalize_logo_cleanup(?string $oldName): void
{
    if ($oldName === null || $oldName === '') {
        return;
    }
    invoice_delete_logo_file($oldName);
}

/**
 * Normalize a posted/stored logo filename for this invoice id.
 */
function invoice_normalize_logo_name(string $name, int $invoiceId, string $fallback = ''): string
{
    $name = basename(trim($name));
    if ($name === '') {
        return '';
    }
    if ($invoiceId > 0 && invoice_logo_belongs_to_invoice($name, $invoiceId)) {
        return $name;
    }
    if ($invoiceId < 1 && invoice_logo_filename_valid($name)) {
        return $name;
    }
    $fallback = basename(trim($fallback));
    if ($fallback !== '' && ($invoiceId < 1 || invoice_logo_belongs_to_invoice($fallback, $invoiceId))) {
        return $fallback;
    }
    return '';
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
 * List filter separate from search: draft / unpaid (unpaid+done) / paid.
 */
function normalize_invoice_list_filter(string $filter): string
{
    $filter = strtolower(trim($filter));
    return in_array($filter, ['draft', 'unpaid', 'paid'], true) ? $filter : '';
}

/**
 * @param array{q?:string,filter?:string,client_id?:int,p?:int} $opts
 */
function invoice_list_query(array $opts = []): string
{
    $params = ['page' => 'admin_invoices'];
    $q = trim((string) ($opts['q'] ?? ''));
    $filter = normalize_invoice_list_filter((string) ($opts['filter'] ?? ''));
    $clientId = (int) ($opts['client_id'] ?? 0);
    $p = max(1, (int) ($opts['p'] ?? 1));
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($filter !== '') {
        $params['filter'] = $filter;
    }
    if ($clientId > 0) {
        $params['client_id'] = (string) $clientId;
    }
    if ($p > 1) {
        $params['p'] = (string) $p;
    }
    $bits = [];
    foreach ($params as $k => $v) {
        $bits[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return 'index.php?' . implode('&', $bits);
}

/**
 * Compact pager pages. 0 is an ellipsis gap between kept numbers.
 *
 * @return list<int>
 */
function invoice_list_page_numbers(int $current, int $total): array
{
    $current = max(1, $current);
    $total = max(1, $total);
    if ($total <= 7) {
        return range(1, $total);
    }
    $keep = [1, $total, $current, $current - 1, $current + 1];
    $keep = array_values(array_unique(array_filter(
        $keep,
        static function ($n) use ($total): bool {
            return $n >= 1 && $n <= $total;
        }
    )));
    sort($keep, SORT_NUMERIC);
    $out = [];
    $prev = 0;
    foreach ($keep as $n) {
        if ($prev > 0 && $n > $prev + 1) {
            $out[] = 0;
        }
        $out[] = $n;
        $prev = $n;
    }
    return $out;
}

/** Native <select> is enough below this many clients; typeahead kicks in at or above. */
function invoice_generate_client_typeahead_min(): int
{
    return 8;
}

/**
 * @param array{name?:string,unpaid_live_count?:int|string,completed_count?:int|string} $client
 */
function invoice_generate_client_option_label(array $client): string
{
    $name = trim((string) ($client['name'] ?? ''));
    $unpaid = (int) ($client['unpaid_live_count'] ?? 0);
    $completed = (int) ($client['completed_count'] ?? 0);
    return $name . ' (' . $unpaid . ' unpaid LIVE · ' . $completed . ' completed)';
}

/**
 * @return list<array<string,mixed>>
 */
function list_invoices(array $opts = []): array
{
    ensure_invoice_schema();
    $limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
    $offset = max(0, (int) ($opts['offset'] ?? 0));
    [$whereSql, $params] = invoices_where_sql($opts);
    $sql = "SELECT i.*,
                (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count
         FROM invoices i"
        . $whereSql
        . ' ORDER BY i.invoice_date DESC, i.id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Unpaid invoices that can still receive unpaid LIVE rows (Draft or generated Done).
 * Paid bills stay locked.
 *
 * @return list<array<string,mixed>>
 */
function list_invoices_open_for_append(int $limit = 50): array
{
    ensure_invoice_schema();
    $limit = max(1, min(200, $limit));
    $sql = "SELECT i.*,
                (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count
         FROM invoices i
         WHERE COALESCE(i.payment_status, 'unpaid') <> 'paid'
         ORDER BY CASE WHEN COALESCE(i.work_status, 'done') = 'draft' THEN 1 ELSE 0 END,
                  i.invoice_date DESC, i.id DESC
         LIMIT " . $limit;
    try {
        return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Keep a chosen unpaid invoice in the Add-to-existing list even when it is
 * older than the recent-invoice cap. Without this the radio is "existing"
 * but the select has no match, and Generate stays disabled.
 *
 * @param list<array<string,mixed>> $open
 * @return list<array<string,mixed>>
 */
function invoice_with_open_append_option(array $open, ?array $invoice): array
{
    if (!$invoice || !invoice_can_append_orders($invoice)) {
        return $open;
    }
    $id = (int) ($invoice['id'] ?? 0);
    if ($id < 1) {
        return $open;
    }
    foreach ($open as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $open;
        }
    }
    if (!array_key_exists('item_count', $invoice)) {
        $invoice['item_count'] = count(list_invoice_items($id));
    }
    array_unshift($open, $invoice);

    return $open;
}

/**
 * @param array{q?:string,filter?:string,client_id?:int} $opts
 * @return array{0:string,1:list<mixed>}
 */
function invoices_where_sql(array $opts = []): array
{
    $clauses = [];
    $params = [];
    $q = trim((string) ($opts['q'] ?? ''));
    if ($q !== '') {
        [$searchSql, $searchParams] = invoices_search_sql($q);
        if ($searchSql !== '') {
            $clauses[] = $searchSql;
            array_push($params, ...$searchParams);
        }
    }
    $filter = normalize_invoice_list_filter((string) ($opts['filter'] ?? ''));
    if ($filter === 'draft') {
        $clauses[] = "i.work_status='draft'";
    } elseif ($filter === 'unpaid') {
        $clauses[] = "i.payment_status='unpaid' AND i.work_status='done'";
    } elseif ($filter === 'paid') {
        $clauses[] = "i.payment_status='paid'";
    }
    $clientId = (int) ($opts['client_id'] ?? 0);
    if ($clientId > 0) {
        $clauses[] = 'i.client_id=?';
        $params[] = $clientId;
    }
    if (!$clauses) {
        return ['', []];
    }
    return [' WHERE ' . implode(' AND ', $clauses), $params];
}

/**
 * List search: number / bill-as / note, plus Draft and Waiting labels (not SQL work_status done).
 *
 * @return array{0:string,1:list<mixed>}
 */
function invoices_search_sql(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['', []];
    }
    $like = '%' . $q . '%';
    $or = [
        'i.invoice_number LIKE ?',
        'i.client_name LIKE ?',
        'i.bill_to_name LIKE ?',
        "IFNULL(i.admin_note, '') LIKE ?",
        'i.payment_status LIKE ?',
    ];
    $params = [$like, $like, $like, $like, $like];
    $ql = mb_strtolower($q);
    if (preg_match('/\bwaiting\b/u', $ql)) {
        $or[] = "(i.payment_status='unpaid' AND COALESCE(i.work_status, 'done')='done')";
    }
    if (preg_match('/\bdraft\b/u', $ql)) {
        $or[] = "i.work_status='draft'";
    }

    return ['(' . implode(' OR ', $or) . ')', $params];
}

function count_invoices(array $opts = []): int
{
    ensure_invoice_schema();
    [$whereSql, $params] = invoices_where_sql($opts);
    $stmt = db()->prepare('SELECT COUNT(*) FROM invoices i' . $whereSql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function count_invoices_by_work_status(string $status): int
{
    ensure_invoice_schema();
    $status = normalize_invoice_work_status($status);
    $stmt = db()->prepare('SELECT COUNT(*) FROM invoices WHERE work_status=?');
    $stmt->execute([$status]);
    return (int) $stmt->fetchColumn();
}

/**
 * Generated invoices waiting for payment (blank drafts are counted separately).
 */
function count_invoices_unpaid(): int
{
    ensure_invoice_schema();
    return (int) db()->query(
        "SELECT COUNT(*) FROM invoices
         WHERE payment_status='unpaid' AND work_status='done'"
    )->fetchColumn();
}

/** Waiting bills whose invoice date is at least $days ago. */
function count_invoices_waiting_older_than(int $days = 14): int
{
    ensure_invoice_schema();
    $days = max(1, min(365, $days));
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM invoices
             WHERE payment_status='unpaid' AND COALESCE(work_status, 'done')='done'
               AND invoice_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Waiting unpaid totals grouped by bill-as (largest first).
 *
 * @return list<array{bill_as:string,n:int,total:float}>
 */
function list_invoices_waiting_totals_by_bill_as(int $limit = 8): array
{
    ensure_invoice_schema();
    $limit = max(1, min(40, $limit));
    try {
        $sql = "SELECT
                    TRIM(COALESCE(NULLIF(TRIM(bill_to_name), ''), client_name)) AS bill_as,
                    COUNT(*) AS n,
                    COALESCE(SUM(total_amount), 0) AS total
                 FROM invoices
                 WHERE payment_status='unpaid' AND COALESCE(work_status, 'done')='done'
                 GROUP BY bill_as
                 HAVING bill_as <> ''
                 ORDER BY total DESC, n DESC
                 LIMIT " . $limit;
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $bill = trim((string) ($row['bill_as'] ?? ''));
        if ($bill === '') {
            continue;
        }
        $out[] = [
            'bill_as' => $bill,
            'n' => (int) ($row['n'] ?? 0),
            'total' => round((float) ($row['total'] ?? 0), 2),
        ];
    }
    return $out;
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

/**
 * Order-management rows stored on this invoice's lines.
 *
 * @return list<array<string,mixed>>
 */
function list_invoice_linked_order_items(int $invoiceId): array
{
    $ids = [];
    foreach (list_invoice_items($invoiceId) as $item) {
        foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
            if ($oid > 0) {
                $ids[$oid] = $oid;
            }
        }
    }
    if (!$ids) {
        return [];
    }
    return list_order_items_by_ids(array_values($ids));
}

/**
 * Snapshot OM rows at bill time: site, LIVE URL, article doc, decided price.
 *
 * @param list<int|string> $orderIds
 * @return list<array{order_item_id:int,site_name:string,live_url:string,article_doc_url:string,decided_price:?float}>
 */
function invoice_snapshot_order_rows(array $orderIds): array
{
    try {
        $ids = [];
        foreach ($orderIds as $oid) {
            $oid = (int) $oid;
            if ($oid > 0 && !isset($ids[$oid])) {
                $ids[$oid] = $oid;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach (list_order_items_by_ids($ids) as $item) {
            $byId[(int) ($item['id'] ?? 0)] = $item;
        }
        $out = [];
        foreach ($ids as $id) {
            $item = $byId[$id] ?? null;
            if (!$item) {
                $out[] = [
                    'order_item_id' => $id,
                    'site_name' => '',
                    'live_url' => '',
                    'article_doc_url' => '',
                    'decided_price' => null,
                ];
                continue;
            }
            $decided = $item['decided_price'] ?? null;
            $out[] = [
                'order_item_id' => $id,
                'site_name' => (string) ($item['site_name'] ?? ''),
                'live_url' => (string) ($item['live_url'] ?? ''),
                'article_doc_url' => (string) ($item['article_doc_url'] ?? ''),
                'decided_price' => ($decided !== null && $decided !== '') ? (float) $decided : null,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Durable invoice audit row. Never throws — billing must not fail if history cannot write.
 *
 * @param array<string,mixed> $payload
 */
function invoice_record_event(?int $invoiceId, string $eventType, ?int $actorUserId, string $summary, array $payload = []): void
{
    try {
        ensure_invoice_schema();
        $eventType = trim($eventType);
        if ($eventType === '') {
            return;
        }
        if ($actorUserId === null || $actorUserId < 1) {
            $u = function_exists('current_user') ? current_user() : null;
            $actorUserId = $u ? (int) ($u['id'] ?? 0) : 0;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $stmt = db()->prepare(
            'INSERT INTO invoice_events (invoice_id, event_type, actor_user_id, summary, payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $invoiceId !== null && $invoiceId > 0 ? $invoiceId : null,
            mb_substr($eventType, 0, 40),
            $actorUserId > 0 ? $actorUserId : null,
            mb_substr(trim($summary), 0, 500),
            $json,
        ]);
    } catch (Throwable $e) {
        // History must never block billing.
    }
}

function invoice_event_type_label(string $type): string
{
    $map = [
        'created' => 'Created',
        'sites_added' => 'Sites added',
        'bill_as_saved' => 'Bill as saved',
        'marked_sent' => 'Marked sent',
        'marked_paid' => 'Marked paid',
        'note_saved' => 'Note saved',
        'blank_saved' => 'Blank invoice saved',
        'lines_saved' => 'Line items saved',
        'deleted' => 'Deleted',
    ];
    $type = trim($type);
    return $map[$type] ?? $type;
}

function invoice_event_actor_label(array $event): string
{
    $name = trim((string) ($event['actor_full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($event['actor_username'] ?? ''));
    }
    return $name !== '' ? $name : 'System';
}

/** @return list<array<string,mixed>> */
function list_invoice_events(int $invoiceId): array
{
    if ($invoiceId < 1) {
        return [];
    }
    try {
        ensure_invoice_schema();
        $stmt = db()->prepare(
            'SELECT e.*, u.username AS actor_username, u.full_name AS actor_full_name
             FROM invoice_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE e.invoice_id = ?
             ORDER BY e.id DESC'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$row) {
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);
        $row['payload_data'] = is_array($decoded) ? $decoded : [];
    }
    unset($row);
    return $rows;
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
function list_invoiceable_order_items(int $clientId = 0): array
{
    ensure_order_schema();
    if ($clientId > 0) {
        $stmt = db()->prepare(
            "SELECT * FROM order_items
             WHERE client_id=? AND row_type='site'
               AND COALESCE(order_stage, 'processing') = 'completed'
               AND TRIM(live_url) <> ''
               AND TRIM(country) <> ''
               AND TRIM(client_label) <> ''
               AND COALESCE(is_paid, 0) = 0
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$clientId]);
        return filter_order_items_not_on_open_invoice($stmt->fetchAll() ?: []);
    }
    $stmt = db()->query(
        "SELECT * FROM order_items
         WHERE row_type='site'
           AND COALESCE(order_stage, 'processing') = 'completed'
           AND TRIM(live_url) <> ''
           AND TRIM(country) <> ''
           AND TRIM(client_label) <> ''
           AND COALESCE(is_paid, 0) = 0
         ORDER BY COALESCE(order_date, DATE(created_at)) DESC, id DESC"
    );
    return filter_order_items_not_on_open_invoice($stmt->fetchAll() ?: []);
}

/**
 * @param list<int> $ids
 * @return list<array<string,mixed>>
 */
function list_invoiceable_order_items_by_ids(array $ids): array
{
    $rows = list_order_items_by_ids($ids);
    $out = [];
    foreach ($rows as $row) {
        if (order_is_completed($row) && !order_is_paid($row)
            && trim((string) ($row['live_url'] ?? '')) !== ''
            && trim((string) ($row['country'] ?? '')) !== ''
            && trim((string) ($row['client_label'] ?? '')) !== '') {
            $out[] = $row;
        }
    }
    return filter_order_items_not_on_open_invoice($out);
}

/** Free-text bill-as from order rows (email or name). */
function invoice_bill_as_from_orders(array $rows): string
{
    return implode(', ', invoice_bill_as_labels($rows));
}

/**
 * Unique client email/name values from order rows, first-seen order.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<string>
 */
function invoice_bill_as_labels(array $rows): array
{
    $labels = [];
    foreach ($rows as $row) {
        $v = trim((string) ($row['client_label'] ?? ''));
        $key = invoice_bill_as_key($v);
        if ($key === '') {
            continue;
        }
        if (!isset($labels[$key])) {
            $labels[$key] = $v;
        }
    }
    return array_values($labels);
}

function invoice_rows_have_mixed_bill_as(array $rows): bool
{
    return count(invoice_bill_as_labels($rows)) > 1;
}

function invoice_assert_single_bill_as(array $rows): void
{
    if (invoice_rows_have_mixed_bill_as($rows)) {
        throw new InvalidArgumentException(
            'Tick rows with the same bill-as (email or name). Mixed clients cannot share one invoice.'
        );
    }
}

/** Max unpaid LIVE rows shown on Generate when opened from Invoices (no ids=). */
function invoice_generate_pick_cap(): int
{
    return 200;
}

/**
 * Why Generate has nothing to tick.
 *
 * @return array{completed_unpaid:int,missing_country_client:int,on_open_invoice:int,invoiceable:int}
 */
function invoice_generate_empty_stats(): array
{
    ensure_order_schema();
    try {
        $rows = db()->query(
            "SELECT id, country, client_label FROM order_items
             WHERE row_type='site'
               AND COALESCE(order_stage, 'processing') = 'completed'
               AND TRIM(live_url) <> ''
               AND COALESCE(is_paid, 0) = 0"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
    $missing = 0;
    $readyIds = [];
    foreach ($rows as $row) {
        if (trim((string) ($row['country'] ?? '')) === ''
            || trim((string) ($row['client_label'] ?? '')) === '') {
            $missing++;
        } else {
            $readyIds[] = (int) ($row['id'] ?? 0);
        }
    }
    $onOpen = 0;
    if ($readyIds) {
        $onOpen = count(order_items_on_open_invoices($readyIds));
    }
    return [
        'completed_unpaid' => count($rows),
        'missing_country_client' => $missing,
        'on_open_invoice' => $onOpen,
        'invoiceable' => max(0, count($readyIds) - $onOpen),
    ];
}

/** Bill-as shown on the list and printable bill (email/name; no client folder). */
function invoice_display_bill_as(array $invoice): string
{
    $name = trim((string) ($invoice['bill_to_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($invoice['client_name'] ?? ''));
    }
    return $name;
}

function invoice_bill_as_key(string $value): string
{
    return mb_strtolower(trim($value));
}

/**
 * First open (Waiting then Draft) unpaid invoice with this bill-as.
 *
 * @return ?array<string,mixed>
 */
function invoice_match_open_for_bill_as(string $billAs): ?array
{
    $key = invoice_bill_as_key($billAs);
    if ($key === '') {
        return null;
    }
    ensure_invoice_schema();
    try {
        $stmt = db()->prepare(
            "SELECT i.*,
                    (SELECT COUNT(*) FROM invoice_items ii WHERE ii.invoice_id = i.id) AS item_count
             FROM invoices i
             WHERE COALESCE(i.payment_status, 'unpaid') <> 'paid'
               AND LOWER(TRIM(CASE
                    WHEN TRIM(COALESCE(i.bill_to_name, '')) <> '' THEN i.bill_to_name
                    ELSE COALESCE(i.client_name, '')
               END)) = ?
             ORDER BY CASE WHEN COALESCE(i.work_status, 'done') = 'draft' THEN 1 ELSE 0 END,
                      i.invoice_date DESC, i.id DESC
             LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Throwable $e) {
        foreach (list_invoices_open_for_append(200) as $inv) {
            if (invoice_bill_as_key(invoice_display_bill_as($inv)) === $key) {
                return $inv;
            }
        }
    }
    return null;
}

/**
 * One Draft/Waiting invoice per bill-as, including bills older than the picker cap.
 * Waiting (already sent) wins over Draft. Used so Add to existing can select a bill
 * that is not in the recent 50-row list.
 *
 * @return array<string, array{id:int,number:string,total:string,status:string}>
 */
function invoice_open_append_targets(): array
{
    ensure_invoice_schema();
    try {
        $rows = db()->query(
            "SELECT id, invoice_number, bill_to_name, client_name, work_status, payment_status, total_amount
             FROM invoices
             WHERE COALESCE(payment_status, 'unpaid') <> 'paid'
             ORDER BY CASE WHEN COALESCE(work_status, 'done') = 'draft' THEN 1 ELSE 0 END,
                      invoice_date DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $inv) {
        $key = invoice_bill_as_key(invoice_display_bill_as($inv));
        if ($key === '' || isset($out[$key])) {
            continue;
        }
        $out[$key] = [
            'id' => (int) ($inv['id'] ?? 0),
            'number' => (string) ($inv['invoice_number'] ?? ''),
            'total' => number_format((float) ($inv['total_amount'] ?? 0), 2, '.', ''),
            'status' => invoice_append_status_label($inv),
        ];
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function invoice_rows_for_bill_as(array $rows, string $billAs): array
{
    $key = invoice_bill_as_key($billAs);
    if ($key === '') {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (invoice_bill_as_key((string) ($row['client_label'] ?? '')) === $key) {
            $out[] = $row;
        }
    }
    return $out;
}

function invoice_generate_href_for_orders(array $orderIds, int $existingInvoiceId = 0): string
{
    $ids = [];
    foreach ($orderIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $href = 'index.php?page=admin_invoice_generate';
    if ($ids) {
        $href .= '&ids=' . implode(',', $ids);
    }
    if ($existingInvoiceId > 0) {
        $href .= '&existing=' . $existingInvoiceId;
    }
    return $href;
}

function invoice_has_extra_bill_details(array $invoice): bool
{
    foreach (['bill_to_address', 'bill_to_hrb', 'bill_to_vat', 'cost_center', 'orderer'] as $key) {
        if (trim((string) ($invoice[$key] ?? '')) !== '') {
            return true;
        }
    }
    $supplier = trim((string) ($invoice['supplier_number'] ?? ''));
    return $supplier !== '' && strtoupper($supplier) !== 'NEW';
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
 * Unpaid/draft invoices that still include these order rows.
 *
 * @param list<int> $orderItemIds
 * @return array<int, array{id:int,invoice_number:string,work_status:string,payment_status:string}>
 */
function order_items_on_open_invoices(array $orderItemIds): array
{
    ensure_invoice_schema();
    $want = [];
    foreach ($orderItemIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $want[$id] = true;
        }
    }
    if (!$want) {
        return [];
    }
    try {
        $rows = db()->query(
            "SELECT i.id, i.invoice_number, i.work_status, i.payment_status, ii.order_item_ids
             FROM invoices i
             INNER JOIN invoice_items ii ON ii.invoice_id = i.id
             WHERE COALESCE(i.payment_status, 'unpaid') <> 'paid'
               AND TRIM(ii.order_item_ids) <> ''
             ORDER BY i.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        foreach (parse_order_item_ids((string) ($row['order_item_ids'] ?? '')) as $oid) {
            if (!isset($want[$oid]) || isset($out[$oid])) {
                continue;
            }
            $out[$oid] = [
                'id' => (int) $row['id'],
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'work_status' => (string) ($row['work_status'] ?? 'done'),
                'payment_status' => (string) ($row['payment_status'] ?? 'unpaid'),
            ];
        }
        if (count($out) === count($want)) {
            break;
        }
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function filter_order_items_not_on_open_invoice(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    $on = order_items_on_open_invoices($ids);
    if (!$on) {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        if (!isset($on[(int) ($row['id'] ?? 0)])) {
            $out[] = $row;
        }
    }
    return $out;
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

/**
 * Draft = not sent yet (still building). Done = sent to the client, waiting for payment.
 * Sheet Generate creates Done invoices. Blank invoices start as Draft.
 */
function invoice_work_status(array $invoice): string
{
    $status = strtolower(trim((string) ($invoice['work_status'] ?? 'done')));
    return $status === 'draft' ? 'draft' : 'done';
}

function invoice_is_draft(array $invoice): bool
{
    return invoice_work_status($invoice) === 'draft';
}

/** Unpaid Done invoice — already with the client, waiting for payment. */
function invoice_is_sent_for_payment(array $invoice): bool
{
    return !invoice_is_paid($invoice) && invoice_work_status($invoice) === 'done';
}

/** Unpaid invoices can still receive more unpaid LIVE rows. Paid stays locked. */
function invoice_can_append_orders(array $invoice): bool
{
    return !invoice_is_paid($invoice);
}

/** Draft vs waiting for payment — used on Add to existing and the invoice list. */
function invoice_append_status_label(array $invoice): string
{
    if (invoice_is_paid($invoice)) {
        return 'Paid';
    }
    if (invoice_is_draft($invoice)) {
        return 'Draft';
    }

    return 'Waiting';
}

function invoice_generate_append_href(int $invoiceId): string
{
    $invoiceId = max(0, $invoiceId);
    if ($invoiceId < 1) {
        return 'index.php?page=admin_invoice_generate';
    }

    return 'index.php?page=admin_invoice_generate&existing=' . $invoiceId;
}

/** Empty blank draft — €0 and no line items — so the list can de-emphasize it. */
function invoice_list_is_incomplete(array $invoice): bool
{
    if (!invoice_is_draft($invoice)) {
        return false;
    }
    $items = (int) ($invoice['item_count'] ?? 0);
    $total = (float) ($invoice['total_amount'] ?? 0);

    return $items < 1 && $total <= 0.00001;
}

function invoice_work_status_label(array $invoice): string
{
    return invoice_append_status_label($invoice);
}

function normalize_invoice_work_status(string $status): string
{
    $status = strtolower(trim($status));
    return $status === 'draft' ? 'draft' : 'done';
}

function invoice_admin_note(array $invoice): string
{
    return trim((string) ($invoice['admin_note'] ?? ''));
}

function update_invoice_admin_note(int $invoiceId, string $note): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    $note = trim($note);
    if (mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 255);
    }
    db()->prepare('UPDATE invoices SET admin_note=?, updated_at=NOW() WHERE id=?')->execute([$note, $invoiceId]);
    invoice_record_event($invoiceId, 'note_saved', null, $note !== '' ? 'Note saved.' : 'Note cleared.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => (float) ($invoice['total_amount'] ?? 0),
        'rows' => [],
    ]);
}

/**
 * Fix bill-as / optional address / date / note on an unpaid invoice.
 * Does not change line items — use update_generated_invoice() for that.
 *
 * @param array<string,mixed> $header
 */
function update_invoice_bill_header(int $invoiceId, array $header): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (invoice_is_paid($invoice)) {
        throw new InvalidArgumentException('Paid invoices cannot be edited.');
    }

    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = (string) $invoice['invoice_date'];
    }
    $adminNote = trim((string) ($header['admin_note'] ?? ''));
    if (mb_strlen($adminNote) > 255) {
        $adminNote = mb_substr($adminNote, 0, 255);
    }
    $billName = trim((string) ($header['bill_to_name'] ?? ''));
    $supplier = trim((string) ($header['supplier_number'] ?? 'NEW')) ?: 'NEW';

    db()->prepare(
        'UPDATE invoices SET
            invoice_date=?, admin_note=?,
            client_name=?, bill_to_name=?, bill_to_address=?, bill_to_hrb=?, bill_to_vat=?,
            supplier_number=?, cost_center=?, orderer=?,
            updated_at=NOW()
         WHERE id=?'
    )->execute([
        $invoiceDate,
        $adminNote,
        $billName,
        $billName,
        trim((string) ($header['bill_to_address'] ?? '')),
        trim((string) ($header['bill_to_hrb'] ?? '')),
        trim((string) ($header['bill_to_vat'] ?? '')),
        $supplier,
        trim((string) ($header['cost_center'] ?? '')),
        trim((string) ($header['orderer'] ?? '')),
        $invoiceId,
    ]);
    invoice_record_event($invoiceId, 'bill_as_saved', null, 'Bill as saved.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => (float) ($invoice['total_amount'] ?? 0),
        'rows' => [],
        'bill_to_name' => $billName,
    ]);
}

/**
 * Save bill-as plus line items on an unpaid order invoice.
 * Existing lines keep only order-sheet ids that were already on this invoice.
 * New lines are not linked. Order-sheet prices are left unchanged.
 * Removing a line drops its ids so those sites can be invoiced again.
 *
 * @param array<string,mixed> $header
 * @param list<array{description?:string,amount?:float|string,qty?:int|string,order_item_ids?:string|list<int>}> $lines
 */
function update_generated_invoice(int $invoiceId, array $header, array $lines): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (invoice_is_manual($invoice)) {
        throw new InvalidArgumentException('Blank invoices are saved separately.');
    }
    if (invoice_is_paid($invoice)) {
        throw new InvalidArgumentException('Paid invoices cannot be edited.');
    }

    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = (string) $invoice['invoice_date'];
    }
    $adminNote = trim((string) ($header['admin_note'] ?? ''));
    if (mb_strlen($adminNote) > 255) {
        $adminNote = mb_substr($adminNote, 0, 255);
    }

    $alreadyOnInvoice = [];
    foreach (list_invoice_items($invoiceId) as $item) {
        foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
            $alreadyOnInvoice[$oid] = true;
        }
    }

    $normalized = [];
    $total = 0.0;
    $sort = 0;
    $usedIds = [];
    foreach ($lines as $line) {
        $desc = trim((string) ($line['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $amount = parse_money($line['amount'] ?? 0);
        $qty = max(1, (int) ($line['qty'] ?? 1));
        $lineTotal = round($amount * $qty, 2);
        $posted = $line['order_item_ids'] ?? '';
        if (is_array($posted)) {
            $posted = implode(',', array_map('strval', $posted));
        }
        $keep = [];
        foreach (parse_order_item_ids((string) $posted) as $oid) {
            if (!isset($alreadyOnInvoice[$oid]) || isset($usedIds[$oid])) {
                continue;
            }
            $usedIds[$oid] = true;
            $keep[] = $oid;
        }
        $normalized[] = [
            'description' => $desc,
            'amount' => $amount,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'order_item_ids' => $keep,
            'sort_order' => $sort++,
        ];
        $total += $lineTotal;
    }
    if (!$normalized) {
        throw new InvalidArgumentException('Add at least one line item with a description.');
    }
    $total = round($total, 2);

    $billName = trim((string) ($header['bill_to_name'] ?? ''));
    $supplier = trim((string) ($header['supplier_number'] ?? 'NEW')) ?: 'NEW';
    $company = invoice_company_defaults();
    $logoName = invoice_normalize_logo_name(
        array_key_exists('company_logo', $header)
            ? (string) $header['company_logo']
            : (string) ($invoice['company_logo'] ?? ''),
        $invoiceId,
        (string) ($invoice['company_logo'] ?? '')
    );
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE invoices SET
                invoice_date=?, admin_note=?,
                client_name=?, bill_to_name=?, bill_to_address=?, bill_to_hrb=?, bill_to_vat=?,
                supplier_number=?, cost_center=?, orderer=?,
                company_name=?, company_bic=?, company_iban=?, company_phone=?,
                company_address=?, company_reg_no=?, company_logo=?, vat_note=?,
                total_amount=?, updated_at=NOW()
             WHERE id=? AND is_manual=0'
        )->execute([
            $invoiceDate,
            $adminNote,
            $billName,
            $billName,
            trim((string) ($header['bill_to_address'] ?? '')),
            trim((string) ($header['bill_to_hrb'] ?? '')),
            trim((string) ($header['bill_to_vat'] ?? '')),
            $supplier,
            trim((string) ($header['cost_center'] ?? '')),
            trim((string) ($header['orderer'] ?? '')),
            trim((string) ($header['company_name'] ?? $invoice['company_name'] ?? $company['company_name'])),
            trim((string) ($header['company_bic'] ?? $invoice['company_bic'] ?? $company['company_bic'])),
            trim((string) ($header['company_iban'] ?? $invoice['company_iban'] ?? $company['company_iban'])),
            trim((string) ($header['company_phone'] ?? $invoice['company_phone'] ?? $company['company_phone'])),
            trim((string) ($header['company_address'] ?? $invoice['company_address'] ?? $company['company_address'])),
            trim((string) ($header['company_reg_no'] ?? $invoice['company_reg_no'] ?? $company['company_reg_no'])),
            $logoName,
            trim((string) ($header['vat_note'] ?? $invoice['vat_note'] ?? $company['vat_note'])),
            $total,
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
                implode(',', $line['order_item_ids']),
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

    invoice_record_event($invoiceId, 'lines_saved', null, 'Line items saved.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => $total,
        'rows' => invoice_snapshot_order_rows(array_keys($usedIds)),
        'bill_to_name' => $billName,
    ]);
}

/**
 * Create an empty blank invoice in the normal bill format (no order-sheet link).
 */
function create_blank_invoice(?int $createdBy): int
{
    $company = invoice_company_defaults();
    return create_invoice([
        'is_manual' => 1,
        'work_status' => 'draft',
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
 * @param string $workStatus draft = still needs data; done = sent, waiting for payment
 */
function update_blank_invoice(int $invoiceId, array $header, array $lines, string $workStatus = 'draft'): void
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

    $workStatus = normalize_invoice_work_status($workStatus);

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

    $total = round($total, 2);
    if ($workStatus === 'done' && $total <= 0) {
        throw new InvalidArgumentException(
            'Cannot mark as Done with a zero total. Add line amounts, or Save as draft while the invoice still needs data.'
        );
    }

    $billName = trim((string) ($header['bill_to_name'] ?? ''));
    $logoName = invoice_normalize_logo_name(
        array_key_exists('company_logo', $header)
            ? (string) $header['company_logo']
            : (string) ($invoice['company_logo'] ?? ''),
        $invoiceId,
        (string) ($invoice['company_logo'] ?? '')
    );
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE invoices SET
                invoice_date=?, admin_note=?, work_status=?,
                client_name=?, bill_to_name=?, bill_to_address=?, bill_to_hrb=?, bill_to_vat=?,
                supplier_number=?, cost_center=?, orderer=?,
                company_name=?, company_bic=?, company_iban=?, company_phone=?,
                company_address=?, company_reg_no=?, company_logo=?, vat_note=?,
                total_amount=?, updated_at=NOW()
             WHERE id=? AND is_manual=1'
        )->execute([
            $invoiceDate,
            $adminNote,
            $workStatus,
            $billName,
            $billName,
            trim((string) ($header['bill_to_address'] ?? '')),
            trim((string) ($header['bill_to_hrb'] ?? '')),
            trim((string) ($header['bill_to_vat'] ?? '')),
            trim((string) ($header['supplier_number'] ?? 'NEW')) ?: 'NEW',
            trim((string) ($header['cost_center'] ?? '')),
            trim((string) ($header['orderer'] ?? '')),
            trim((string) ($header['company_name'] ?? $invoice['company_name'] ?? $company['company_name'])),
            trim((string) ($header['company_bic'] ?? $invoice['company_bic'] ?? $company['company_bic'])),
            trim((string) ($header['company_iban'] ?? $invoice['company_iban'] ?? $company['company_iban'])),
            trim((string) ($header['company_phone'] ?? $invoice['company_phone'] ?? $company['company_phone'])),
            trim((string) ($header['company_address'] ?? $invoice['company_address'] ?? $company['company_address'])),
            trim((string) ($header['company_reg_no'] ?? $invoice['company_reg_no'] ?? $company['company_reg_no'])),
            $logoName,
            trim((string) ($header['vat_note'] ?? $invoice['vat_note'] ?? $company['vat_note'])),
            $total,
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
    invoice_record_event($invoiceId, 'blank_saved', null, $workStatus === 'done' ? 'Blank invoice saved as done.' : 'Blank invoice saved as draft.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => $total,
        'rows' => [],
    ]);
}

/**
 * @param list<array{order_item_ids?:list<int>}> $normalized
 * @return list<int>
 */
function invoice_order_ids_from_lines(array $normalized): array
{
    $ids = [];
    foreach ($normalized as $line) {
        foreach ((array) ($line['order_item_ids'] ?? []) as $oid) {
            $oid = (int) $oid;
            if ($oid > 0) {
                $ids[] = $oid;
            }
        }
    }
    return $ids;
}

/**
 * Paid / LIVE / country / client checks for sheet invoice lines.
 *
 * @param list<array{order_item_ids?:list<int>}> $normalized
 */
function invoice_assert_order_rows_ready(array $normalized): void
{
    foreach ($normalized as $line) {
        foreach ((array) ($line['order_item_ids'] ?? []) as $oid) {
            $oid = (int) $oid;
            if ($oid < 1) {
                continue;
            }
            $chk = db()->prepare(
                "SELECT is_paid, live_url, country, client_label FROM order_items
                 WHERE id=? AND row_type='site' LIMIT 1"
            );
            $chk->execute([$oid]);
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
            if (trim((string) ($row['country'] ?? '')) === '') {
                throw new InvalidArgumentException('Country is required before pushing a row to an invoice.');
            }
            if (trim((string) ($row['client_label'] ?? '')) === '') {
                throw new InvalidArgumentException('Client email or name is required before pushing a row to an invoice.');
            }
        }
    }
}

/**
 * Block rows that already sit on a different unpaid/draft invoice.
 *
 * @param list<array{order_item_ids?:list<int>}> $normalized
 */
function invoice_assert_not_on_other_open_invoice(array $normalized, int $ignoreInvoiceId = 0): void
{
    $onOpen = order_items_on_open_invoices(invoice_order_ids_from_lines($normalized));
    if (!$onOpen) {
        return;
    }
    $nums = [];
    foreach ($onOpen as $inv) {
        if ($ignoreInvoiceId > 0 && (int) ($inv['id'] ?? 0) === $ignoreInvoiceId) {
            continue;
        }
        $num = trim((string) ($inv['invoice_number'] ?? ''));
        if ($num !== '') {
            $nums[$num] = true;
        }
    }
    if ($nums) {
        throw new InvalidArgumentException(
            'Already on invoice ' . implode(', ', array_keys($nums))
            . '. Open that bill instead of generating another.'
        );
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
    if (!$isManual) {
        invoice_assert_order_rows_ready($normalized);
        invoice_assert_not_on_other_open_invoice($normalized, 0);
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
                    currency, total_amount, payment_status, is_manual, work_status, admin_note, created_by
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $workStatus = $isManual
                ? normalize_invoice_work_status((string) ($header['work_status'] ?? 'draft'))
                : 'done';
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
                $workStatus,
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
            $orderIds = $isManual ? [] : invoice_order_ids_from_lines($normalized);
            $rowCount = count($orderIds);
            invoice_record_event($invoiceId, 'created', $createdBy, $isManual
                ? 'Created blank invoice.'
                : ('Created invoice with ' . $rowCount . ' site' . ($rowCount === 1 ? '' : 's') . '.'), [
                'invoice_number' => $invoiceNumber,
                'total_before' => 0,
                'total_after' => round($total, 2),
                'rows' => invoice_snapshot_order_rows($orderIds),
            ]);
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
 * Description for the order ids that will actually be added.
 * A grouped line can list URLs whose rows are already on this invoice; qty is
 * then only the new ids, so the text must drop the ones we skip.
 *
 * @param list<int> $keep
 */
function invoice_description_for_kept_order_ids(array $keep, string $fallback): string
{
    if (!$keep) {
        return $fallback;
    }
    $byId = [];
    foreach (list_order_items_by_ids($keep) as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }
    $urls = [];
    $placement = [];
    foreach ($keep as $oid) {
        $row = $byId[(int) $oid] ?? null;
        if (!$row) {
            continue;
        }
        if (order_is_placement($row)) {
            $placement[] = order_invoice_description($row);
            continue;
        }
        $url = trim((string) ($row['live_url'] ?? ''));
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    if ($placement !== [] && $urls === []) {
        return count($placement) === 1 ? $placement[0] : implode("\n\n", $placement);
    }
    if ($urls !== [] && $placement === []) {
        return 'Article Published -' . "\n" . implode("\n", $urls);
    }
    return $fallback;
}

/**
 * Add unpaid LIVE order lines onto an existing unpaid invoice (same invoice number).
 *
 * @param list<array{description:string,amount:float|string,qty:int|string,line_total?:float|string,order_item_ids?:list<int>}> $lines
 * @param list<array<string,mixed>> $picked
 * @return array{id:int,added:int,invoice_number:string}
 */
function append_orders_to_invoice(int $invoiceId, array $lines, array $picked): array
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (($invoice['payment_status'] ?? 'unpaid') === 'paid' || !invoice_can_append_orders($invoice)) {
        throw new InvalidArgumentException('Paid invoices cannot take more orders. Generate a new bill instead.');
    }
    invoice_assert_single_bill_as($picked);
    $existingBill = invoice_display_bill_as($invoice);
    $fromOrders = invoice_bill_as_from_orders($picked);
    if ($existingBill !== '' && $fromOrders !== ''
        && mb_strtolower($existingBill) !== mb_strtolower($fromOrders)) {
        throw new InvalidArgumentException(
            'Those rows are billed as ' . $fromOrders
            . '. Invoice ' . (string) ($invoice['invoice_number'] ?? '')
            . ' is billed as ' . $existingBill . '.'
        );
    }

    $normalized = [];
    $sortBase = 0;
    foreach (list_invoice_items($invoiceId) as $item) {
        $sortBase = max($sortBase, (int) ($item['sort_order'] ?? 0) + 1);
    }
    $sort = $sortBase;
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
            'order_item_ids' => array_values(array_filter(array_map('intval', (array) ($line['order_item_ids'] ?? [])), static fn ($id) => $id > 0)),
            'sort_order' => $sort++,
        ];
    }
    if (!$normalized) {
        throw new InvalidArgumentException('Select at least one unpaid LIVE row to add.');
    }
    invoice_assert_order_rows_ready($normalized);
    invoice_assert_not_on_other_open_invoice($normalized, $invoiceId);

    $onThis = order_items_on_open_invoices(invoice_order_ids_from_lines($normalized));
    $filtered = [];
    $addedTotal = 0.0;
    $addedRows = 0;
    foreach ($normalized as $line) {
        $keep = [];
        foreach ($line['order_item_ids'] as $oid) {
            $oid = (int) $oid;
            if (isset($onThis[$oid]) && (int) ($onThis[$oid]['id'] ?? 0) === $invoiceId) {
                continue;
            }
            $keep[] = $oid;
        }
        if (!$keep) {
            continue;
        }
        $qty = count($keep);
        $amount = (float) $line['amount'];
        $lineTotal = round($amount * $qty, 2);
        $desc = (string) $line['description'];
        if (count($keep) !== count($line['order_item_ids'])) {
            $desc = invoice_description_for_kept_order_ids($keep, $desc);
        }
        $filtered[] = [
            'description' => $desc,
            'amount' => $amount,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'order_item_ids' => $keep,
            'sort_order' => $line['sort_order'],
        ];
        $addedTotal += $lineTotal;
        $addedRows += $qty;
    }
    if (!$filtered) {
        throw new InvalidArgumentException('Those rows are already on this invoice.');
    }

    $newTotal = round((float) ($invoice['total_amount'] ?? 0) + $addedTotal, 2);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, amount, qty, line_total, order_item_ids, sort_order)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($filtered as $line) {
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
        $billTo = $existingBill !== '' ? $existingBill : $fromOrders;
        $isManual = 0;
        $workStatus = invoice_is_draft($invoice) ? 'draft' : 'done';
        $pdo->prepare(
            'UPDATE invoices
             SET total_amount=?, bill_to_name=?, client_name=?, is_manual=?, work_status=?, updated_at=NOW()
             WHERE id=?'
        )->execute([
            $newTotal,
            $billTo !== '' ? $billTo : (string) ($invoice['bill_to_name'] ?? ''),
            $billTo !== '' ? $billTo : (string) ($invoice['client_name'] ?? ''),
            $isManual,
            $workStatus,
            $invoiceId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $addedIds = invoice_order_ids_from_lines($filtered);
    invoice_record_event($invoiceId, 'sites_added', null, 'Added ' . $addedRows . ' site' . ($addedRows === 1 ? '' : 's') . '.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => round((float) ($invoice['total_amount'] ?? 0), 2),
        'total_after' => $newTotal,
        'rows' => invoice_snapshot_order_rows($addedIds),
    ]);

    return [
        'id' => $invoiceId,
        'added' => $addedRows,
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
    ];
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
    if (invoice_is_draft($invoice)) {
        throw new InvalidArgumentException(
            'This invoice is still a Draft. Save as Done first (sent / waiting for payment), then mark Paid.'
        );
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
                    set_order_item_paid($oid, $clientId, true);
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
    $paidIds = [];
    foreach ($items as $item) {
        foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
            if ($oid > 0) {
                $paidIds[] = $oid;
            }
        }
    }
    invoice_record_event($invoiceId, 'marked_paid', null, 'Payment marked received.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => (float) ($invoice['total_amount'] ?? 0),
        'rows' => invoice_snapshot_order_rows($paidIds),
    ]);
}

function invoice_is_paid(array $invoice): bool
{
    return ($invoice['payment_status'] ?? 'unpaid') === 'paid';
}

/** Mark a Draft invoice as sent (Done — waiting for payment). */
function mark_invoice_sent(int $invoiceId): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($invoiceId);
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if (invoice_is_paid($invoice)) {
        throw new InvalidArgumentException('Paid invoices are already finished.');
    }
    if (!invoice_is_draft($invoice)) {
        return;
    }
    if (invoice_list_is_incomplete($invoice) || (float) ($invoice['total_amount'] ?? 0) <= 0.00001) {
        throw new InvalidArgumentException('Save as Done needs a total above €0.');
    }
    db()->prepare(
        "UPDATE invoices SET work_status='done', is_manual=?, updated_at=NOW() WHERE id=?"
    )->execute([
        invoice_is_manual($invoice) ? 1 : 0,
        $invoiceId,
    ]);
    $sentIds = [];
    foreach (list_invoice_items($invoiceId) as $item) {
        foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
            if ($oid > 0) {
                $sentIds[] = $oid;
            }
        }
    }
    invoice_record_event($invoiceId, 'marked_sent', null, 'Marked as sent — waiting for payment.', [
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'total_before' => (float) ($invoice['total_amount'] ?? 0),
        'total_after' => (float) ($invoice['total_amount'] ?? 0),
        'rows' => invoice_snapshot_order_rows($sentIds),
    ]);
}

function delete_invoice(int $id): void
{
    ensure_invoice_schema();
    $invoice = get_invoice($id);
    if ($invoice) {
        $orderIds = [];
        foreach (list_invoice_items($id) as $item) {
            foreach (parse_order_item_ids((string) ($item['order_item_ids'] ?? '')) as $oid) {
                if ($oid > 0) {
                    $orderIds[] = $oid;
                }
            }
        }
        invoice_record_event($id, 'deleted', null, 'Invoice deleted.', [
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'total_before' => (float) ($invoice['total_amount'] ?? 0),
            'total_after' => 0,
            'rows' => invoice_snapshot_order_rows($orderIds),
        ]);
        $logo = basename(trim((string) ($invoice['company_logo'] ?? '')));
        db()->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        if ($logo !== '') {
            invoice_delete_logo_file($logo);
        }
        return;
    }
    db()->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
}
