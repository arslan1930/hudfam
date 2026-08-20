<?php
/**
 * Extracting Sites with Emails pipeline.
 *
 * Block 1 (extract_queue): Team 1 submits sites needing extraction (by country).
 * Claim/open: Team 2 opens a batch → those rows leave Block 1.
 * Block 2 (extract_sites): Team 2 pastes final extracted domains (by country).
 * Emails (extract_site_emails): Team 3 attaches 2–4 emails under each site.
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
        "CREATE TABLE IF NOT EXISTS extract_queue (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          country VARCHAR(100) NOT NULL DEFAULT '',
          notes TEXT NULL,
          submitted_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_queue_country_domain (country, domain),
          INDEX (country),
          INDEX (submitted_by),
          INDEX (created_at),
          CONSTRAINT fk_eq_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_sites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          domain VARCHAR(255) NOT NULL,
          country VARCHAR(100) NOT NULL DEFAULT '',
          notes TEXT NULL,
          added_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_sites_country_domain (country, domain),
          INDEX (country),
          INDEX (added_by),
          CONSTRAINT fk_es_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_site_emails (
          id INT AUTO_INCREMENT PRIMARY KEY,
          extract_site_id INT NOT NULL,
          email VARCHAR(190) NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_extract_email (extract_site_id, email),
          INDEX (extract_site_id),
          CONSTRAINT fk_ese_site FOREIGN KEY (extract_site_id) REFERENCES extract_sites(id) ON DELETE CASCADE,
          CONSTRAINT fk_ese_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS extract_claims (
          id INT AUTO_INCREMENT PRIMARY KEY,
          token CHAR(32) NOT NULL,
          user_id INT NOT NULL,
          country VARCHAR(100) NOT NULL DEFAULT '',
          queue_ids_json TEXT NOT NULL,
          domains_json TEXT NOT NULL,
          status ENUM('pending','opened') NOT NULL DEFAULT 'pending',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          opened_at DATETIME NULL,
          UNIQUE KEY uniq_extract_claim_token (token),
          INDEX (user_id, status),
          CONSTRAINT fk_ec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Optional work_type on tasks for deep-links
    try {
        ensure_tasks_schema();
        $cols = $pdo->query('SHOW COLUMNS FROM team_tasks')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('work_type', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE team_tasks
                 ADD COLUMN work_type VARCHAR(40) NOT NULL DEFAULT 'sites' AFTER niche"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return array<string, string> */
function extract_work_types(): array
{
    return [
        'sites' => 'Sites Data (Filter & add)',
        'extract_submit' => 'Extraction · submit to Block 1',
        'extract_claim' => 'Extraction · claim Block 1 → extract',
        'extract_final' => 'Extraction · paste Block 2',
        'extract_emails' => 'Extraction · add emails',
    ];
}

function extract_work_type_label(string $type): string
{
    $map = extract_work_types();
    return $map[$type] ?? $type;
}

function extract_team_page_for_work_type(string $type, string $country = ''): string
{
    $qs = $country !== '' ? '&country=' . rawurlencode($country) : '';
    return match ($type) {
        'extract_submit' => 'index.php?page=team_extract_submit' . $qs,
        'extract_claim' => 'index.php?page=team_extract_queue' . $qs,
        'extract_final' => 'index.php?page=team_extract_final' . $qs,
        'extract_emails' => 'index.php?page=team_extract_emails' . $qs,
        default => 'index.php?page=team_prospect_check' . $qs,
    };
}

/**
 * @return list<array{country:string,region_label:string,queue:int,extracted:int,with_emails:int}>
 */
function extract_country_folders(): array
{
    ensure_extract_schema();
    if (function_exists('seed_countries_if_empty')) {
        try {
            seed_countries_if_empty(db());
        } catch (Throwable $e) {
        }
    }

    $queue = [];
    foreach (db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total FROM extract_queue GROUP BY TRIM(country)"
    )->fetchAll() as $row) {
        $key = canonicalize_country_name(trim((string) $row['country']));
        $queue[$key] = ($queue[$key] ?? 0) + (int) $row['total'];
    }
    $extracted = [];
    foreach (db()->query(
        "SELECT TRIM(country) AS country, COUNT(*) AS total FROM extract_sites GROUP BY TRIM(country)"
    )->fetchAll() as $row) {
        $key = canonicalize_country_name(trim((string) $row['country']));
        $extracted[$key] = ($extracted[$key] ?? 0) + (int) $row['total'];
    }
    $withEmails = [];
    foreach (db()->query(
        "SELECT TRIM(s.country) AS country, COUNT(DISTINCT s.id) AS total
         FROM extract_sites s
         INNER JOIN extract_site_emails e ON e.extract_site_id = s.id
         GROUP BY TRIM(s.country)"
    )->fetchAll() as $row) {
        $key = canonicalize_country_name(trim((string) $row['country']));
        $withEmails[$key] = ($withEmails[$key] ?? 0) + (int) $row['total'];
    }

    $folders = [];
    $seen = [];
    foreach (list_countries(null, true) as $c) {
        $name = (string) $c['name'];
        $seen[$name] = true;
        $q = (int) ($queue[$name] ?? 0);
        $ex = (int) ($extracted[$name] ?? 0);
        $em = (int) ($withEmails[$name] ?? 0);
        if ($q === 0 && $ex === 0 && $em === 0) {
            continue; // only show countries that have extract activity
        }
        $reg = (string) $c['region'];
        $folders[] = [
            'country' => $name,
            'region' => $reg,
            'region_label' => regions()[$reg] ?? $reg,
            'queue' => $q,
            'extracted' => $ex,
            'with_emails' => $em,
        ];
        unset($queue[$name], $extracted[$name], $withEmails[$name]);
    }
    foreach (array_unique(array_merge(array_keys($queue), array_keys($extracted), array_keys($withEmails))) as $name) {
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $folders[] = [
            'country' => $name,
            'region' => 'other',
            'region_label' => 'Other',
            'queue' => (int) ($queue[$name] ?? 0),
            'extracted' => (int) ($extracted[$name] ?? 0),
            'with_emails' => (int) ($withEmails[$name] ?? 0),
        ];
    }
    usort($folders, static function ($a, $b) {
        if ($a['region_label'] !== $b['region_label']) {
            return $a['region_label'] <=> $b['region_label'];
        }
        return strcasecmp($a['country'], $b['country']);
    });
    return $folders;
}

/**
 * @param list<string> $domains
 * @return array{inserted:int,skipped:int}
 */
function extract_queue_add(array $domains, string $country, array $user, string $notes = ''): array
{
    ensure_extract_schema();
    $country = canonicalize_country_name(trim($country));
    if ($country === '') {
        throw new InvalidArgumentException('Country is required.');
    }
    $clean = [];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if (is_plain_site_domain($d)) {
            $clean[$d] = true;
        } else {
            $n = extract_root_domain_candidate((string) $d);
            if ($n !== '' && is_plain_site_domain($n)) {
                $clean[$n] = true;
            }
        }
    }
    $domains = array_keys($clean);
    if ($domains === []) {
        return ['inserted' => 0, 'skipped' => 0];
    }
    $ins = db()->prepare(
        'INSERT INTO extract_queue (domain, country, notes, submitted_by) VALUES (?,?,?,?)'
    );
    $inserted = 0;
    $skipped = 0;
    foreach ($domains as $d) {
        try {
            $ins->execute([$d, $country, $notes, (int) $user['id']]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/**
 * @return list<array<string,mixed>>
 */
function extract_queue_list(string $country, int $limit = 5000): array
{
    ensure_extract_schema();
    $country = canonicalize_country_name(trim($country));
    $aliases = country_name_match_values($country);
    $ph = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = db()->prepare(
        "SELECT q.*, u.username, u.full_name
         FROM extract_queue q
         LEFT JOIN users u ON u.id = q.submitted_by
         WHERE TRIM(q.country) IN ($ph)
         ORDER BY q.created_at ASC, q.domain ASC
         LIMIT " . (int) $limit
    );
    $stmt->execute($aliases);
    return $stmt->fetchAll();
}

function extract_queue_count(string $country = ''): int
{
    ensure_extract_schema();
    if ($country === '') {
        return (int) db()->query('SELECT COUNT(*) FROM extract_queue')->fetchColumn();
    }
    $aliases = country_name_match_values(canonicalize_country_name($country));
    $ph = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM extract_queue WHERE TRIM(country) IN ($ph)");
    $stmt->execute($aliases);
    return (int) $stmt->fetchColumn();
}

/**
 * Prepare a claim: store selected queue rows under a token (not deleted yet).
 *
 * @param list<int> $ids
 * @return array{token:string,count:int,country:string}
 */
function extract_claim_prepare(array $ids, array $user, string $country): array
{
    ensure_extract_schema();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if ($ids === []) {
        throw new InvalidArgumentException('Select at least one site.');
    }
    $country = canonicalize_country_name(trim($country));
    $aliases = country_name_match_values($country);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $aph = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = db()->prepare(
        "SELECT id, domain, country FROM extract_queue
         WHERE id IN ($ph) AND TRIM(country) IN ($aph)
         ORDER BY domain ASC"
    );
    $stmt->execute(array_merge($ids, $aliases));
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        throw new InvalidArgumentException('No matching sites in Block 1.');
    }
    $token = bin2hex(random_bytes(16));
    $qids = array_map(static fn($r) => (int) $r['id'], $rows);
    $domains = array_map(static fn($r) => (string) $r['domain'], $rows);
    db()->prepare(
        'INSERT INTO extract_claims (token, user_id, country, queue_ids_json, domains_json, status)
         VALUES (?,?,?,?,?,\'pending\')'
    )->execute([
        $token,
        (int) $user['id'],
        $country,
        json_encode($qids),
        json_encode($domains),
    ]);
    return ['token' => $token, 'count' => count($domains), 'country' => $country];
}

/**
 * Open a claim: delete those sites from Block 1 and return the domain list.
 *
 * @return array{domains:string[],country:string,count:int}
 */
function extract_claim_open(string $token, array $user): array
{
    ensure_extract_schema();
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new InvalidArgumentException('Invalid claim link.');
    }
    $stmt = db()->prepare('SELECT * FROM extract_claims WHERE token=? LIMIT 1');
    $stmt->execute([$token]);
    $claim = $stmt->fetch();
    if (!$claim) {
        throw new InvalidArgumentException('Claim not found or already finished.');
    }
    if ((int) $claim['user_id'] !== (int) $user['id'] && !is_admin($user)) {
        throw new InvalidArgumentException('This claim belongs to another user.');
    }
    $domains = json_decode((string) $claim['domains_json'], true) ?: [];
    $qids = json_decode((string) $claim['queue_ids_json'], true) ?: [];
    $country = (string) $claim['country'];

    if ($claim['status'] === 'pending') {
        db()->beginTransaction();
        try {
            $qids = array_values(array_filter(array_map('intval', $qids), static fn($id) => $id > 0));
            if ($qids !== []) {
                foreach (array_chunk($qids, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    db()->prepare("DELETE FROM extract_queue WHERE id IN ($ph)")->execute($chunk);
                }
            }
            db()->prepare(
                "UPDATE extract_claims SET status='opened', opened_at=NOW() WHERE id=?"
            )->execute([(int) $claim['id']]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $e;
        }
    }

    return [
        'domains' => array_values(array_map('strval', $domains)),
        'country' => $country,
        'count' => count($domains),
    ];
}

/**
 * @param list<string> $domains
 * @return array{inserted:int,skipped:int}
 */
function extract_sites_add(array $domains, string $country, array $user, string $notes = ''): array
{
    ensure_extract_schema();
    $country = canonicalize_country_name(trim($country));
    if ($country === '') {
        throw new InvalidArgumentException('Country is required.');
    }
    $clean = [];
    foreach ($domains as $d) {
        $d = strtolower(trim((string) $d));
        if (is_plain_site_domain($d)) {
            $clean[$d] = true;
        } else {
            $n = extract_root_domain_candidate((string) $d);
            if ($n !== '' && is_plain_site_domain($n)) {
                $clean[$n] = true;
            }
        }
    }
    $domains = array_keys($clean);
    if ($domains === []) {
        return ['inserted' => 0, 'skipped' => 0];
    }
    $ins = db()->prepare(
        'INSERT INTO extract_sites (domain, country, notes, added_by) VALUES (?,?,?,?)'
    );
    $inserted = 0;
    $skipped = 0;
    foreach ($domains as $d) {
        try {
            $ins->execute([$d, $country, $notes, (int) $user['id']]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
        }
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/**
 * @return list<array<string,mixed>>
 */
function extract_sites_list(string $country, int $limit = 5000): array
{
    ensure_extract_schema();
    $country = canonicalize_country_name(trim($country));
    $aliases = country_name_match_values($country);
    $ph = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = db()->prepare(
        "SELECT s.*, u.username, u.full_name,
                (SELECT COUNT(*) FROM extract_site_emails e WHERE e.extract_site_id = s.id) AS email_count
         FROM extract_sites s
         LEFT JOIN users u ON u.id = s.added_by
         WHERE TRIM(s.country) IN ($ph)
         ORDER BY s.domain ASC
         LIMIT " . (int) $limit
    );
    $stmt->execute($aliases);
    return $stmt->fetchAll();
}

function extract_sites_count(string $country = ''): int
{
    ensure_extract_schema();
    if ($country === '') {
        return (int) db()->query('SELECT COUNT(*) FROM extract_sites')->fetchColumn();
    }
    $aliases = country_name_match_values(canonicalize_country_name($country));
    $ph = implode(',', array_fill(0, count($aliases), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM extract_sites WHERE TRIM(country) IN ($ph)");
    $stmt->execute($aliases);
    return (int) $stmt->fetchColumn();
}

function get_extract_site(int $id): ?array
{
    ensure_extract_schema();
    $stmt = db()->prepare('SELECT * FROM extract_sites WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function extract_site_emails_list(int $siteId): array
{
    ensure_extract_schema();
    $stmt = db()->prepare(
        'SELECT * FROM extract_site_emails WHERE extract_site_id=? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$siteId]);
    return $stmt->fetchAll();
}

function extract_email_is_valid(string $email): bool
{
    $email = trim($email);
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Replace or add emails for a site. Pass a list of email strings (2–4 typical).
 *
 * @param list<string> $emails
 * @return array{saved:int,skipped:int}
 */
function extract_site_set_emails(int $siteId, array $emails, array $user, bool $replace = false): array
{
    ensure_extract_schema();
    $site = get_extract_site($siteId);
    if (!$site) {
        throw new InvalidArgumentException('Site not found.');
    }
    $clean = [];
    foreach ($emails as $e) {
        $e = strtolower(trim((string) $e));
        if (extract_email_is_valid($e)) {
            $clean[$e] = true;
        }
    }
    $list = array_keys($clean);
    if ($replace) {
        db()->prepare('DELETE FROM extract_site_emails WHERE extract_site_id=?')->execute([$siteId]);
    }
    $ins = db()->prepare(
        'INSERT INTO extract_site_emails (extract_site_id, email, sort_order, created_by) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)'
    );
    $saved = 0;
    $skipped = 0;
    $order = 0;
    if (!$replace) {
        $max = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM extract_site_emails WHERE extract_site_id=?');
        $max->execute([$siteId]);
        $order = (int) $max->fetchColumn() + 1;
    }
    foreach ($list as $e) {
        try {
            $ins->execute([$siteId, $e, $order, (int) $user['id']]);
            $saved++;
            $order++;
        } catch (PDOException $ex) {
            $skipped++;
        }
    }
    return ['saved' => $saved, 'skipped' => $skipped];
}

function extract_site_delete_email(int $emailId, int $siteId): bool
{
    ensure_extract_schema();
    $stmt = db()->prepare('DELETE FROM extract_site_emails WHERE id=? AND extract_site_id=?');
    $stmt->execute([$emailId, $siteId]);
    return $stmt->rowCount() > 0;
}

/**
 * Sites in Block 2 with their emails for the emails panel.
 *
 * @return list<array{site:array,emails:list<array>}>
 */
function extract_sites_with_emails(string $country, int $limit = 2000): array
{
    $sites = extract_sites_list($country, $limit);
    $out = [];
    foreach ($sites as $s) {
        $out[] = [
            'site' => $s,
            'emails' => extract_site_emails_list((int) $s['id']),
        ];
    }
    return $out;
}

function extract_totals(): array
{
    ensure_extract_schema();
    return [
        'queue' => (int) db()->query('SELECT COUNT(*) FROM extract_queue')->fetchColumn(),
        'extracted' => (int) db()->query('SELECT COUNT(*) FROM extract_sites')->fetchColumn(),
        'with_emails' => (int) db()->query(
            'SELECT COUNT(DISTINCT extract_site_id) FROM extract_site_emails'
        )->fetchColumn(),
    ];
}
