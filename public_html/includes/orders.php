<?php
/**
 * Admin Order Management — client sheets with site / prices / profit / live URL.
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
          site_name VARCHAR(255) NOT NULL DEFAULT '',
          owner_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          decided_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          live_url VARCHAR(500) NOT NULL DEFAULT '',
          sort_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (client_id, sort_order),
          CONSTRAINT fk_oi_client FOREIGN KEY (client_id) REFERENCES order_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
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

/**
 * @return list<array<string,mixed>>
 */
function list_order_clients(): array
{
    ensure_order_schema();
    return db()->query(
        "SELECT c.*,
                (SELECT COUNT(*) FROM order_items i WHERE i.client_id = c.id) AS item_count,
                (SELECT COALESCE(SUM(i.decided_price - i.owner_price), 0)
                   FROM order_items i WHERE i.client_id = c.id) AS total_profit
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

/**
 * @return list<array<string,mixed>>
 */
function list_order_items(int $clientId): array
{
    ensure_order_schema();
    $stmt = db()->prepare(
        'SELECT * FROM order_items WHERE client_id=? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

function add_order_item(int $clientId, string $siteName = ''): int
{
    ensure_order_schema();
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM order_items WHERE client_id=?');
    $max->execute([$clientId]);
    $next = (int) $max->fetchColumn() + 1;
    db()->prepare(
        'INSERT INTO order_items (client_id, site_name, owner_price, decided_price, live_url, sort_order)
         VALUES (?,?,0,0,\'\',?)'
    )->execute([$clientId, trim($siteName), $next]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
    return (int) db()->lastInsertId();
}

/**
 * Save one sheet row. Empty site_name rows are kept so the admin can fill them later.
 *
 * @param array{site_name?:string,owner_price?:mixed,decided_price?:mixed,live_url?:string} $data
 */
function update_order_item(int $itemId, int $clientId, array $data): void
{
    ensure_order_schema();
    db()->prepare(
        'UPDATE order_items
         SET site_name=?, owner_price=?, decided_price=?, live_url=?, updated_at=NOW()
         WHERE id=? AND client_id=?'
    )->execute([
        trim((string) ($data['site_name'] ?? '')),
        parse_money($data['owner_price'] ?? 0),
        parse_money($data['decided_price'] ?? 0),
        trim((string) ($data['live_url'] ?? '')),
        $itemId,
        $clientId,
    ]);
    db()->prepare('UPDATE order_clients SET updated_at=NOW() WHERE id=?')->execute([$clientId]);
}

/**
 * Bulk-save sheet rows from POST arrays keyed by item id.
 *
 * @param array<int|string,string> $sites
 * @param array<int|string,mixed> $owner
 * @param array<int|string,mixed> $decided
 * @param array<int|string,string> $urls
 * @return int number of rows saved
 */
function save_order_sheet_rows(int $clientId, array $sites, array $owner, array $decided, array $urls): int
{
    ensure_order_schema();
    $saved = 0;
    foreach ($sites as $id => $siteName) {
        $itemId = (int) $id;
        if ($itemId <= 0) {
            continue;
        }
        update_order_item($itemId, $clientId, [
            'site_name' => $siteName,
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
