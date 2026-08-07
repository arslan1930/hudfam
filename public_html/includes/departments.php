<?php
/**
 * Office departments — Admin assigns members + tasks;
 * Team members only see departments they belong to.
 */

function department_seed_definitions(): array
{
    return [
        ['slug' => 'site_finding', 'name' => 'Site Finding', 'sort_order' => 10],
        ['slug' => 'site_extracting', 'name' => 'Site Extracting', 'sort_order' => 20],
        ['slug' => 'email_extracting', 'name' => 'Email Extracting', 'sort_order' => 30],
        ['slug' => 'communication', 'name' => 'Communication Team', 'sort_order' => 40],
    ];
}

function ensure_departments_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS departments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(60) NOT NULL,
          name VARCHAR(120) NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_department_slug (slug),
          UNIQUE KEY uniq_department_name (name),
          INDEX (sort_order),
          INDEX (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_members (
          department_id INT NOT NULL,
          user_id INT NOT NULL,
          assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          assigned_by INT NULL,
          PRIMARY KEY (department_id, user_id),
          INDEX (user_id),
          CONSTRAINT fk_dm_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
          CONSTRAINT fk_dm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_dm_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS department_tasks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          department_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          notes TEXT NULL,
          status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
          assigned_to INT NULL,
          created_by INT NULL,
          due_date DATE NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (department_id),
          INDEX (status),
          INDEX (assigned_to),
          INDEX (created_by),
          CONSTRAINT fk_dt_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
          CONSTRAINT fk_dt_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT fk_dt_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    seed_departments_if_empty();
}

function seed_departments_if_empty(): void
{
    $ins = db()->prepare(
        'INSERT INTO departments (slug, name, sort_order, is_active)
         VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), is_active=1'
    );
    foreach (department_seed_definitions() as $d) {
        $ins->execute([$d['slug'], $d['name'], $d['sort_order']]);
    }
}

/**
 * @return list<array<string,mixed>>
 */
function list_departments(bool $activeOnly = true): array
{
    ensure_departments_schema();
    $sql = 'SELECT * FROM departments';
    if ($activeOnly) {
        $sql .= ' WHERE is_active=1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_department_by_slug(string $slug): ?array
{
    ensure_departments_schema();
    $stmt = db()->prepare('SELECT * FROM departments WHERE slug=? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_department(int $id): ?array
{
    ensure_departments_schema();
    $stmt = db()->prepare('SELECT * FROM departments WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return list<int>
 */
function user_department_ids(int $userId): array
{
    ensure_departments_schema();
    $stmt = db()->prepare('SELECT department_id FROM department_members WHERE user_id=?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function user_in_department(int $userId, int $departmentId): bool
{
    ensure_departments_schema();
    $stmt = db()->prepare(
        'SELECT 1 FROM department_members WHERE user_id=? AND department_id=? LIMIT 1'
    );
    $stmt->execute([$userId, $departmentId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Team users who belong to at least one department should see only department work.
 */
function user_is_department_scoped(array $user): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return false;
    }
    return user_department_ids((int) ($user['id'] ?? 0)) !== [];
}

/**
 * @return list<array<string,mixed>>
 */
function list_departments_for_user(int $userId): array
{
    ensure_departments_schema();
    $stmt = db()->prepare(
        'SELECT d.*
         FROM departments d
         INNER JOIN department_members m ON m.department_id = d.id
         WHERE m.user_id=? AND d.is_active=1
         ORDER BY d.sort_order ASC, d.name ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function list_department_members(int $departmentId): array
{
    ensure_departments_schema();
    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.is_active, m.assigned_at
         FROM department_members m
         INNER JOIN users u ON u.id = m.user_id
         WHERE m.department_id=?
         ORDER BY u.full_name ASC, u.username ASC'
    );
    $stmt->execute([$departmentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function list_team_users_for_departments(): array
{
    return db()->query(
        "SELECT id, username, full_name, email, is_active
         FROM users
         WHERE role='team' AND is_active=1
         ORDER BY full_name ASC, username ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function add_department_member(int $departmentId, int $userId, array $admin): bool
{
    ensure_departments_schema();
    $dept = get_department($departmentId);
    if (!$dept) {
        return false;
    }
    $u = db()->prepare("SELECT id, role FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user || ($user['role'] ?? '') !== 'team') {
        return false;
    }
    $adminId = (int) ($admin['id'] ?? 0) ?: null;
    db()->prepare(
        'INSERT INTO department_members (department_id, user_id, assigned_by)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)'
    )->execute([$departmentId, $userId, $adminId]);
    return true;
}

function remove_department_member(int $departmentId, int $userId): bool
{
    ensure_departments_schema();
    $stmt = db()->prepare(
        'DELETE FROM department_members WHERE department_id=? AND user_id=?'
    );
    $stmt->execute([$departmentId, $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * @return array{member_count:int,open_tasks:int,total_tasks:int}
 */
function department_stats(int $departmentId): array
{
    ensure_departments_schema();
    $members = db()->prepare('SELECT COUNT(*) FROM department_members WHERE department_id=?');
    $members->execute([$departmentId]);
    $open = db()->prepare(
        "SELECT COUNT(*) FROM department_tasks WHERE department_id=? AND status IN ('open','in_progress')"
    );
    $open->execute([$departmentId]);
    $total = db()->prepare('SELECT COUNT(*) FROM department_tasks WHERE department_id=?');
    $total->execute([$departmentId]);
    return [
        'member_count' => (int) $members->fetchColumn(),
        'open_tasks' => (int) $open->fetchColumn(),
        'total_tasks' => (int) $total->fetchColumn(),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function list_department_tasks(
    int $departmentId,
    string $status = '',
    ?int $forUserId = null
): array {
    ensure_departments_schema();
    $where = ['t.department_id = ?'];
    $params = [$departmentId];
    if ($status !== '' && in_array($status, ['open', 'in_progress', 'done'], true)) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if ($forUserId !== null) {
        // Members see all department tasks; optional filter for "assigned to me"
        // kept as membership gate outside this function.
    }
    $whereSql = implode(' AND ', $where);
    $stmt = db()->prepare(
        "SELECT t.*,
                au.username AS assigned_username,
                au.full_name AS assigned_name,
                cu.username AS created_username,
                cu.full_name AS created_name
         FROM department_tasks t
         LEFT JOIN users au ON au.id = t.assigned_to
         LEFT JOIN users cu ON cu.id = t.created_by
         WHERE {$whereSql}
         ORDER BY
           FIELD(t.status, 'open', 'in_progress', 'done'),
           t.due_date IS NULL, t.due_date ASC,
           t.id DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function get_department_task(int $taskId): ?array
{
    ensure_departments_schema();
    $stmt = db()->prepare('SELECT * FROM department_tasks WHERE id=? LIMIT 1');
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function save_department_task(
    int $departmentId,
    string $title,
    string $notes,
    string $status,
    ?int $assignedTo,
    ?string $dueDate,
    array $actor,
    ?int $taskId = null
): array {
    ensure_departments_schema();
    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'error' => 'Task title is required.'];
    }
    if (mb_strlen($title) > 255) {
        return ['ok' => false, 'error' => 'Title is too long.'];
    }
    if (!in_array($status, ['open', 'in_progress', 'done'], true)) {
        $status = 'open';
    }
    $notes = trim($notes);
    $due = null;
    if ($dueDate !== null && trim($dueDate) !== '') {
        $due = trim($dueDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return ['ok' => false, 'error' => 'Due date must be YYYY-MM-DD.'];
        }
    }
    if ($assignedTo !== null && $assignedTo > 0) {
        if (!user_in_department($assignedTo, $departmentId)) {
            return ['ok' => false, 'error' => 'Assignee must be a member of this department.'];
        }
    } else {
        $assignedTo = null;
    }

    $actorId = (int) ($actor['id'] ?? 0) ?: null;

    if ($taskId !== null && $taskId > 0) {
        $existing = get_department_task($taskId);
        if (!$existing || (int) $existing['department_id'] !== $departmentId) {
            return ['ok' => false, 'error' => 'Task not found.'];
        }
        db()->prepare(
            'UPDATE department_tasks
             SET title=?, notes=?, status=?, assigned_to=?, due_date=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$title, $notes !== '' ? $notes : null, $status, $assignedTo, $due, $taskId]);
        return ['ok' => true, 'id' => $taskId];
    }

    db()->prepare(
        'INSERT INTO department_tasks
           (department_id, title, notes, status, assigned_to, created_by, due_date)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $departmentId,
        $title,
        $notes !== '' ? $notes : null,
        $status,
        $assignedTo,
        $actorId,
        $due,
    ]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

function delete_department_task(int $taskId): bool
{
    ensure_departments_schema();
    $stmt = db()->prepare('DELETE FROM department_tasks WHERE id=?');
    $stmt->execute([$taskId]);
    return $stmt->rowCount() > 0;
}

function update_department_task_status(int $taskId, string $status): bool
{
    ensure_departments_schema();
    if (!in_array($status, ['open', 'in_progress', 'done'], true)) {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE department_tasks SET status=?, updated_at=NOW() WHERE id=?'
    );
    $stmt->execute([$status, $taskId]);
    return $stmt->rowCount() > 0;
}

/**
 * Open/in-progress tasks across departments the user belongs to.
 *
 * @return list<array<string,mixed>>
 */
function list_open_tasks_for_user(int $userId, int $limit = 50): array
{
    ensure_departments_schema();
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare(
        "SELECT t.*, d.name AS department_name, d.slug AS department_slug
         FROM department_tasks t
         INNER JOIN departments d ON d.id = t.department_id
         INNER JOIN department_members m ON m.department_id = t.department_id AND m.user_id = ?
         WHERE t.status IN ('open','in_progress') AND d.is_active=1
         ORDER BY FIELD(t.status, 'open', 'in_progress'),
                  t.due_date IS NULL, t.due_date ASC, t.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function department_task_status_label(string $status): string
{
    return match ($status) {
        'in_progress' => 'In progress',
        'done' => 'Done',
        default => 'Open',
    };
}
