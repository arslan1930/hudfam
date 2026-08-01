<?php

/**
 * Project-scoped inventory helpers.
 */

function require_project_access(int $projectId, array $user): array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id=?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        flash('error', 'Project not found.');
        redirect(is_admin($user) ? 'index.php?page=admin_projects' : 'index.php?page=team_projects');
    }
    if (!is_admin($user)) {
        $chk = db()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
        $chk->execute([$projectId, $user['id']]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            echo 'You are not assigned to this project.';
            exit;
        }
    }
    return $project;
}

/**
 * Super-search within one project's inventory.
 * Matches domain, URL, blogger email, our mailbox, contact name, niche, notes.
 */
function search_project_inventory(int $projectId, string $q, int $limit = 50): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $domainExact = strtolower(preg_replace('#^https?://#i', '', $q));
    $domainExact = rtrim($domainExact, '/');
    $domainExact = preg_replace('#^www\.#i', '', $domainExact);

    $sql = "SELECT s.*, u.username owner
            FROM sites s
            LEFT JOIN users u ON u.id = s.assigned_to
            WHERE s.primary_project_id = ?
              AND (
                s.domain LIKE ? OR s.url LIKE ? OR s.niche LIKE ?
                OR s.publisher_email LIKE ? OR s.our_mailbox LIKE ?
                OR s.our_contact_name LIKE ? OR s.outreach_notes LIKE ?
                OR s.country LIKE ? OR s.language LIKE ?
                OR s.domain = ?
              )
            ORDER BY
              CASE WHEN s.domain = ? THEN 0
                   WHEN s.domain LIKE ? THEN 1
                   ELSE 2 END,
              s.updated_at DESC
            LIMIT " . (int) $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $projectId,
        $like, $like, $like,
        $like, $like,
        $like, $like,
        $like, $like,
        $domainExact,
        $domainExact,
        $domainExact . '%',
    ]);
    return $stmt->fetchAll();
}

function project_inventory_query(int $projectId, array $filters, int $pageNum = 1, int $per = 50): array
{
    $where = ['s.primary_project_id = ?'];
    $params = [$projectId];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.domain LIKE ? OR s.url LIKE ? OR s.publisher_email LIKE ?
                     OR s.our_mailbox LIKE ? OR s.our_contact_name LIKE ?
                     OR s.niche LIKE ? OR s.outreach_notes LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = $filters['status'];
    }
    apply_site_geo_filters($where, $params, [
        'region' => $filters['region'] ?? '',
        'country' => $filters['country'] ?? '',
        'language' => $filters['language'] ?? '',
    ]);
    if (!empty($filters['mailbox'])) {
        $where[] = 's.our_mailbox = ?';
        $params[] = $filters['mailbox'];
    }

    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM sites s WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageNum = max(1, $pageNum);
    $offset = ($pageNum - 1) * $per;
    $stmt = db()->prepare(
        "SELECT s.*, u.username owner FROM sites s
         LEFT JOIN users u ON u.id = s.assigned_to
         WHERE $whereSql ORDER BY s.updated_at DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'page' => $pageNum,
    ];
}

function distinct_project_mailboxes(int $projectId): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT our_mailbox FROM sites
         WHERE primary_project_id = ? AND our_mailbox <> ''
         ORDER BY our_mailbox"
    );
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'our_mailbox');
}

function distinct_project_languages(int $projectId): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT language FROM sites
         WHERE primary_project_id = ? AND language <> ''
         ORDER BY language"
    );
    $stmt->execute([$projectId]);
    return array_column($stmt->fetchAll(), 'language');
}

function normalize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#^www\.#i', '', $domain);
    $domain = rtrim(explode('/', $domain)[0], '.');
    return $domain;
}
