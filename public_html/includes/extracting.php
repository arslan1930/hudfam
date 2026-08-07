<?php
/**
 * Extracting sites — second path for team adds.
 * Path 1: prospect_sites (admin country database)
 * Path 2: extract_batches / extract_batch_sites (Sites list + Extracting Results)
 *
 * One batch per country, created only when a teammate adds new unique sites.
 */

function ensure_extract_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_batches (
          id INT AUTO_INCREMENT PRIMARY KEY,
          country VARCHAR(100) NOT NULL,
          language VARCHAR(50) NOT NULL DEFAULT '',
          region VARCHAR(40) NOT NULL DEFAULT '',
          site_count INT NOT NULL DEFAULT 0,
          results_text MEDIUMTEXT NULL,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_country (country),
          INDEX (updated_at),
          CONSTRAINT fk_extract_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_batch_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          batch_id INT NOT NULL,
          domain VARCHAR(255) NOT NULL,
          prospect_site_id INT NULL,
          added_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_batch_domain (batch_id, domain),
          INDEX (domain),
          INDEX (added_by),
          CONSTRAINT fk_ebs_batch FOREIGN KEY (batch_id) REFERENCES extract_batches(id) ON DELETE CASCADE,
          CONSTRAINT fk_ebs_site FOREIGN KEY (prospect_site_id) REFERENCES prospect_sites(id) ON DELETE SET NULL,
          CONSTRAINT fk_ebs_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Get existing country extract batch, or create one (first sites for this country).
 */
function get_or_create_extract_batch(
    string $country,
    array $user,
    string $language = '',
    string $region = ''
): int {
    ensure_extract_schema();
    $country = trim($country);
    if ($country === '') {
        throw new InvalidArgumentException('Country is required for Extracting sites.');
    }
    $stmt = db()->prepare('SELECT id FROM extract_batches WHERE country=? LIMIT 1');
    $stmt->execute([$country]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        if ($language !== '' || $region !== '') {
            db()->prepare(
                'UPDATE extract_batches
                 SET language = IF(?<>\'\', ?, language),
                     region = IF(?<>\'\', ?, region),
                     updated_at = NOW()
                 WHERE id=?'
            )->execute([$language, $language, $region, $region, $id]);
        }
        return $id;
    }
    db()->prepare(
        'INSERT INTO extract_batches (country, language, region, site_count, created_by)
         VALUES (?,?,?,0,?)'
    )->execute([$country, $language, $region, (int) ($user['id'] ?? 0) ?: null]);
    return (int) db()->lastInsertId();
}

/**
 * Dual path: push newly added domains into the country's Sites list box.
 *
 * @param list<array{domain:string,prospect_site_id?:int|null}>|list<string> $domains
 * @return array{batch_id:int,added:int}
 */
function add_domains_to_extract_sites(
    array $domains,
    array $user,
    string $country,
    string $language = '',
    string $region = ''
): array {
    ensure_extract_schema();
    $rows = [];
    foreach ($domains as $item) {
        if (is_string($item)) {
            $d = normalize_domain($item);
            if ($d !== '') {
                $rows[] = ['domain' => $d, 'prospect_site_id' => null];
            }
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $d = normalize_domain((string) ($item['domain'] ?? ''));
        if ($d === '') {
            continue;
        }
        $rows[] = [
            'domain' => $d,
            'prospect_site_id' => isset($item['prospect_site_id']) ? (int) $item['prospect_site_id'] : null,
        ];
    }
    if ($rows === []) {
        return ['batch_id' => 0, 'added' => 0];
    }

    $batchId = get_or_create_extract_batch($country, $user, $language, $region);
    $ins = db()->prepare(
        'INSERT INTO extract_batch_sites (batch_id, domain, prospect_site_id, added_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE
           prospect_site_id = COALESCE(VALUES(prospect_site_id), prospect_site_id)'
    );
    $added = 0;
    $uid = (int) ($user['id'] ?? 0) ?: null;
    foreach ($rows as $row) {
        try {
            $ins->execute([
                $batchId,
                $row['domain'],
                $row['prospect_site_id'] ?: null,
                $uid,
            ]);
            // rowCount is 1 for insert, 2 for update on MySQL; only count fresh inserts
            if ($ins->rowCount() === 1) {
                $added++;
            }
        } catch (PDOException $e) {
            // skip bad row
        }
    }
    $cnt = db()->prepare('SELECT COUNT(*) FROM extract_batch_sites WHERE batch_id=?');
    $cnt->execute([$batchId]);
    $siteCount = (int) $cnt->fetchColumn();
    db()->prepare(
        'UPDATE extract_batches SET site_count=?, updated_at=NOW() WHERE id=?'
    )->execute([$siteCount, $batchId]);

    return ['batch_id' => $batchId, 'added' => $added];
}

/**
 * @return list<array<string,mixed>>
 */
function list_extract_batches(int $limit = 200): array
{
    ensure_extract_schema();
    $limit = max(1, min(500, $limit));
    $sql = "SELECT b.*, u.username, u.full_name
            FROM extract_batches b
            LEFT JOIN users u ON u.id = b.created_by
            WHERE b.site_count > 0
            ORDER BY b.updated_at DESC, b.country ASC
            LIMIT {$limit}";
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_extract_batch(int $batchId): ?array
{
    ensure_extract_schema();
    $stmt = db()->prepare(
        'SELECT b.*, u.username, u.full_name
         FROM extract_batches b
         LEFT JOIN users u ON u.id = b.created_by
         WHERE b.id=? LIMIT 1'
    );
    $stmt->execute([$batchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return list<string>
 */
function get_extract_batch_domains(int $batchId, int $limit = 50000): array
{
    ensure_extract_schema();
    $limit = max(1, min(100000, $limit));
    $stmt = db()->prepare(
        'SELECT domain FROM extract_batch_sites WHERE batch_id=? ORDER BY domain ASC LIMIT ' . (int) $limit
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function save_extract_batch_results(int $batchId, string $resultsText): void
{
    ensure_extract_schema();
    db()->prepare(
        'UPDATE extract_batches SET results_text=?, updated_at=NOW() WHERE id=?'
    )->execute([$resultsText, $batchId]);
}

function count_extract_batches(): int
{
    ensure_extract_schema();
    return (int) db()->query('SELECT COUNT(*) FROM extract_batches WHERE site_count > 0')->fetchColumn();
}
