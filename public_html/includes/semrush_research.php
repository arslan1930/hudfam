<?php
/**
 * Semrush Research — Admin seeds site names per country;
 * Site Finding edits the sheet (copy/cut/undo) and adds comments.
 */

function ensure_semrush_research_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS semrush_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL,
          domain VARCHAR(255) NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_semrush_country_domain (country, domain),
          INDEX (country),
          INDEX (updated_at),
          CONSTRAINT fk_semrush_site_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS semrush_sheet_comments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL,
          body TEXT NOT NULL,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (country, created_at),
          CONSTRAINT fk_semrush_comment_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Countries that have at least one Semrush site (newest activity first).
 *
 * @return list<array{country:string,total:int,updated_at:string}>
 */
function list_semrush_country_rows(): array
{
    ensure_semrush_research_schema();
    $rows = db()->query(
        "SELECT TRIM(country) AS country,
                COUNT(*) AS total,
                MAX(updated_at) AS updated_at
         FROM semrush_sites
         WHERE TRIM(country) <> ''
         GROUP BY TRIM(country)
         ORDER BY updated_at DESC, country ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'country' => (string) ($r['country'] ?? ''),
            'total' => (int) ($r['total'] ?? 0),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ];
    }
    return $out;
}

function count_semrush_sites_for_country(string $country): int
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM semrush_sites WHERE country=?');
    $stmt->execute([$canon['name']]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return list<string>
 */
function list_semrush_domains_for_country(string $country): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT domain FROM semrush_sites WHERE country=? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$canon['name']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $d) {
        $d = trim((string) $d);
        if ($d !== '') {
            $out[] = $d;
        }
    }
    return $out;
}

function semrush_domains_text(string $country): string
{
    return implode("\n", list_semrush_domains_for_country($country));
}

/**
 * Replace the full site-name list for a country from pasted/edited text.
 *
 * @return array{ok:bool,error?:string,country?:string,total?:int,inserted?:int,removed?:int,domains?:list<string>}
 */
function set_semrush_domains_from_text(string $country, string $raw, array $user): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return ['ok' => false, 'error' => 'Select an existing country.'];
    }
    $countryName = $canon['name'];
    $parsed = function_exists('parse_domain_list_strict')
        ? parse_domain_list_strict($raw)
        : ['valid' => [], 'invalid_count' => 0];
    $valid = $parsed['valid'] ?? [];
    $unique = [];
    foreach ($valid as $d) {
        $n = normalize_domain((string) $d);
        $root = function_exists('to_root_domain') ? to_root_domain($n) : $n;
        if ($root !== '') {
            $unique[$root] = true;
        }
    }
    $list = array_keys($unique);

    $pdo = db();
    $existing = list_semrush_domains_for_country($countryName);
    $existingSet = array_fill_keys($existing, true);
    $newSet = array_fill_keys($list, true);

    $toRemove = array_values(array_filter($existing, static fn ($d) => !isset($newSet[$d])));
    $toAdd = array_values(array_filter($list, static fn ($d) => !isset($existingSet[$d])));

    if ($toRemove !== []) {
        $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
        $params = array_merge([$countryName], $toRemove);
        $pdo->prepare(
            "DELETE FROM semrush_sites WHERE country=? AND domain IN ({$placeholders})"
        )->execute($params);
    }

    $uid = (int) ($user['id'] ?? 0) ?: null;
    $ins = $pdo->prepare(
        'INSERT INTO semrush_sites (country, domain, sort_order, created_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), updated_at=NOW()'
    );
    $order = 0;
    foreach ($list as $domain) {
        $ins->execute([$countryName, $domain, $order, $uid]);
        $order++;
    }

    return [
        'ok' => true,
        'country' => $countryName,
        'total' => count($list),
        'inserted' => count($toAdd),
        'removed' => count($toRemove),
        'domains' => $list,
        'invalid' => (int) ($parsed['invalid_count'] ?? 0),
    ];
}

/**
 * Append sites to a country (Admin seed). Does not remove existing.
 *
 * @return array{ok:bool,error?:string,country?:string,inserted?:int,skipped?:int,total?:int,invalid?:int}
 */
function add_semrush_domains(string $country, string $raw, array $user): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return ['ok' => false, 'error' => 'Select an existing country.'];
    }
    $countryName = $canon['name'];
    $parsed = parse_domain_list_strict($raw);
    if ($parsed['valid'] === []) {
        return [
            'ok' => false,
            'error' => ((int) $parsed['invalid_count'] > 0)
                ? 'No valid site names — fix invalid lines first.'
                : 'Paste at least one site name.',
            'invalid' => (int) $parsed['invalid_count'],
        ];
    }

    $existing = array_fill_keys(list_semrush_domains_for_country($countryName), true);
    $maxOrder = (int) db()->query(
        'SELECT COALESCE(MAX(sort_order), -1) FROM semrush_sites WHERE country=' . db()->quote($countryName)
    )->fetchColumn();
    $uid = (int) ($user['id'] ?? 0) ?: null;
    $ins = db()->prepare(
        'INSERT INTO semrush_sites (country, domain, sort_order, created_by)
         VALUES (?,?,?,?)'
    );
    $inserted = 0;
    $skipped = 0;
    $order = $maxOrder + 1;
    foreach ($parsed['valid'] as $d) {
        $root = to_root_domain(normalize_domain((string) $d));
        if ($root === '') {
            continue;
        }
        if (isset($existing[$root])) {
            $skipped++;
            continue;
        }
        try {
            $ins->execute([$countryName, $root, $order, $uid]);
            $existing[$root] = true;
            $inserted++;
            $order++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }

    return [
        'ok' => true,
        'country' => $countryName,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'total' => count_semrush_sites_for_country($countryName),
        'invalid' => (int) $parsed['invalid_count'],
    ];
}

function clear_semrush_country(string $country): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return ['ok' => false, 'error' => 'Country not found.'];
    }
    $name = $canon['name'];
    $n = count_semrush_sites_for_country($name);
    db()->prepare('DELETE FROM semrush_sites WHERE country=?')->execute([$name]);
    // Keep comments when clearing sites? Clear both for a clean empty state.
    db()->prepare('DELETE FROM semrush_sheet_comments WHERE country=?')->execute([$name]);
    return ['ok' => true, 'country' => $name, 'cleared' => $n];
}

/**
 * @return list<array{id:int,body:string,created_at:string,created_by:int,username:string,full_name:string}>
 */
function list_semrush_comments(string $country, int $limit = 100): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare(
        "SELECT c.id, c.body, c.created_at, c.created_by, u.username, u.full_name
         FROM semrush_sheet_comments c
         LEFT JOIN users u ON u.id = c.created_by
         WHERE c.country=?
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$canon['name']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'body' => (string) ($r['body'] ?? ''),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'created_by' => (int) ($r['created_by'] ?? 0),
            'username' => (string) ($r['username'] ?? ''),
            'full_name' => (string) ($r['full_name'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function add_semrush_comment(string $country, string $body, array $user): array
{
    ensure_semrush_research_schema();
    $canon = resolve_canonical_country($country);
    if (!$canon) {
        return ['ok' => false, 'error' => 'Country not found.'];
    }
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '') {
        return ['ok' => false, 'error' => 'Comment cannot be empty.'];
    }
    if (mb_strlen($body) > 4000) {
        $body = mb_substr($body, 0, 4000);
    }
    $uid = (int) ($user['id'] ?? 0) ?: null;
    db()->prepare(
        'INSERT INTO semrush_sheet_comments (country, body, created_by) VALUES (?,?,?)'
    )->execute([$canon['name'], $body, $uid]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/**
 * @return array{ok:bool,error?:string}
 */
function delete_semrush_comment(int $commentId, array $user): array
{
    ensure_semrush_research_schema();
    if ($commentId < 1) {
        return ['ok' => false, 'error' => 'Comment not found.'];
    }
    $stmt = db()->prepare('SELECT id, created_by FROM semrush_sheet_comments WHERE id=? LIMIT 1');
    $stmt->execute([$commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Comment not found.'];
    }
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $owner = (int) ($row['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    if (!$isAdmin && !$owner) {
        return ['ok' => false, 'error' => 'You can only delete your own comments.'];
    }
    db()->prepare('DELETE FROM semrush_sheet_comments WHERE id=?')->execute([$commentId]);
    return ['ok' => true];
}

function semrush_sheet_url(string $country, bool $admin = false): string
{
    $page = $admin ? 'admin_semrush_sheet' : 'team_semrush_sheet';
    return 'index.php?page=' . $page . '&country=' . rawurlencode($country);
}

function semrush_hub_url(bool $admin = false): string
{
    return 'index.php?page=' . ($admin ? 'admin_semrush_research' : 'team_semrush_research');
}
