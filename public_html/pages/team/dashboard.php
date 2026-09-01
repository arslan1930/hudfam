<?php
$user = require_team();
$uid = (int) $user['id'];
ensure_departments_schema();

$deptScoped = user_is_department_scoped($user);
$awaitsDept = team_user_awaits_department($user);
$myDepartments = $deptScoped ? list_departments_for_user($uid) : [];
$myTasks = $deptScoped ? list_open_tasks_for_user($uid, 40) : [];

if ($deptScoped && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) post('action') === 'set_status') {
    $taskId = (int) post('task_id');
    $status = (string) post('status');
    $task = get_department_task($taskId);
    $back = 'index.php?page=team_dashboard';
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if (!$task || !user_in_department($uid, (int) $task['department_id'])) {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Task not found.']);
            exit;
        }
        flash('error', 'Task not found.');
        redirect($back);
    }
    if (!team_can_set_department_task_status($user, $task)) {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only the assignee can update this task.']);
            exit;
        }
        flash('error', 'Only the assignee can update this task.');
        redirect($back);
    }
    if (!update_department_task_status($taskId, $status)) {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid status.']);
            exit;
        }
        flash('error', 'Invalid status.');
        redirect($back);
    }
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'task_id' => $taskId,
            'status' => $status,
            'message' => 'Status updated.',
        ]);
        exit;
    }
    flash('ok', 'Status updated.');
    redirect($back);
}

if ($awaitsDept) {
    render_header('Dashboard', 'team');
    ?>
    <div class="topbar">
      <div>
        <h1>Waiting for assignment</h1>
        <p class="muted">Your Team login works, but you are not in a department yet.</p>
      </div>
    </div>
    <div class="card">
      <div class="empty-state">
        <p>No department assigned.</p>
        <p class="muted">
          Ask Admin to add you to a department (Site Finding, Site Extracting, Email Extracting, or Communication).
          Your tools will appear in the sidebar after that.
        </p>
      </div>
    </div>
    <?php render_dashboard_help('team'); ?>
    <?php
    render_footer('team');
    return;
}

if ($deptScoped) {
    render_header('Your work', 'team');
    ?>
    <div class="topbar">
      <div>
        <h1>Your work</h1>
        <p class="muted">
          You are assigned to
          <?= count($myDepartments) ?> department<?= count($myDepartments) === 1 ? '' : 's' ?>.
          Tasks are below. Tools are in the sidebar.
        </p>
      </div>
      <div class="actions" style="align-items:center;flex-wrap:wrap;gap:0.55rem">
        <label class="sheet-search dashboard-search" for="dashboard-search">
          <span class="visually-hidden">Filter this page</span>
          <input id="dashboard-search" type="search"
                 placeholder="<?= count($myDepartments) > 1 ? 'Find a task or tool…' : 'Find a task…' ?>"
                 autocomplete="off" spellcheck="false" data-no-draft
                 title="Type to filter · Enter = next match · Shift+Enter = previous">
          <span class="sheet-search-meta muted" data-dashboard-search-meta hidden></span>
        </label>
        <a class="btn secondary" href="index.php?page=team_departments">My departments</a>
      </div>
    </div>

    <p class="muted" data-dashboard-search-empty hidden>No matches on this page.</p>

    <div class="card">
      <h2>Open tasks</h2>
      <?php if (!$myTasks): ?>
        <div class="empty-state">
          <p>No open tasks right now.</p>
          <p class="muted">When Admin assigns work to your department, it appears here.</p>
        </div>
      <?php else:
        $showDue = department_tasks_have_due_date($myTasks);
        ?>
        <div class="table-wrap">
          <table class="dept-task-table sheet-cards-mobile">
            <thead>
              <tr>
                <th>Department</th>
                <th>Task</th>
                <th>Assigned</th>
                <th>Status</th>
                <?php if ($showDue): ?><th>Due</th><?php endif; ?>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($myTasks as $t):
                $mine = (int) ($t['assigned_to'] ?? 0) === $uid;
                $overdue = department_task_is_overdue($t);
                $canSetStatus = team_can_set_department_task_status($user, $t);
                $slug = (string) ($t['department_slug'] ?? '');
                $assigneeLabel = department_task_assignee_label($t);
                $toolUrl = department_primary_tool_url($slug);
                $folderUrl = department_folder_url($slug);
                $openLabel = department_task_open_label($slug);
                $creatorLabel = department_task_creator_label($t);
                $rowClass = trim(($mine ? 'dept-task-mine' : '') . ($overdue ? ' dept-task-overdue' : ''));
                $hay = mb_strtolower(trim(
                    (string) $t['department_name'] . ' '
                    . (string) $t['title'] . ' '
                    . department_task_status_label((string) $t['status']) . ' '
                    . $assigneeLabel . ' '
                    . (string) ($t['notes'] ?? '') . ' '
                    . (string) ($t['due_date'] ?? '')
                    . ($mine ? ' yours' : '')
                    . ($overdue ? ' overdue' : '')
                    . ' ' . $openLabel
                ));
                ?>
              <tr<?= $rowClass !== '' ? ' class="' . h($rowClass) . '"' : '' ?>
                  data-dashboard-item data-search="<?= h($hay) ?>"
                  data-due="<?= h((string) ($t['due_date'] ?? '')) ?>">
                <td data-label="Department"><?= h((string) $t['department_name']) ?></td>
                <td data-label="Task">
                  <strong><?= h((string) $t['title']) ?></strong>
                  <?php if ($mine): ?><span class="badge">Yours</span>
                  <?php elseif ((int) ($t['assigned_to'] ?? 0) < 1): ?><span class="badge">Whole department</span><?php endif; ?>
                  <?php if ($overdue): ?><span class="badge" data-overdue-badge>Overdue</span><?php endif; ?>
                  <?php if (trim((string) ($t['notes'] ?? '')) !== ''): ?>
                    <div class="help dept-task-notes"><?= nl2br(h((string) $t['notes'])) ?></div>
                  <?php endif; ?>
                  <?php if ($creatorLabel !== ''): ?>
                    <div class="muted dept-task-created">From <?= h($creatorLabel) ?></div>
                  <?php endif; ?>
                </td>
                <td class="muted" data-label="Assigned"><?= h($assigneeLabel) ?></td>
                <td data-label="Status">
                  <?php if ($canSetStatus): ?>
                  <form method="post" action="index.php?page=team_dashboard" class="inline-form" data-stay-ajax>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                    <select name="status" data-stay-ajax-change aria-label="Task status">
                      <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done'] as $val => $lab): ?>
                        <option value="<?= h($val) ?>" <?= (string) $t['status'] === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                  <?php else: ?>
                    <?= h(department_task_status_label((string) $t['status'])) ?>
                  <?php endif; ?>
                </td>
                <?php if ($showDue): ?>
                <td class="muted<?= $overdue ? ' dept-due-overdue' : '' ?>" data-label="Due"><?= h((string) ($t['due_date'] ?: '—')) ?></td>
                <?php endif; ?>
                <td data-label="Open">
                  <div class="dept-task-actions">
                    <a class="btn secondary small" href="<?= h($toolUrl) ?>" title="<?= h($openLabel) ?>">Open</a>
                    <a class="muted" href="<?= h($folderUrl) ?>">Tasks</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($myTasks) >= 40): ?>
          <p class="help">Showing the 40 newest open tasks. Use a department folder to see the rest.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php
    $toolPages = department_tool_pages_for_user($user);
    $toolSet = array_fill_keys($toolPages, true);
    $toolCards = [];
    if (!empty($toolSet['team_prospect_check'])) {
        $toolCards[] = ['team_prospect_check', 'Filter & add', 'Paste → filter → add unique sites'];
        $toolCards[] = ['team_prospect_batches', 'Site adding history', 'Your daily adds'];
    }
    if (!empty($toolSet['team_semrush_research'])) {
        $toolCards[] = ['team_semrush_research', 'Semrush Research', 'From Extracting Push · edit, comment'];
    }
    if (!empty($toolSet['team_site_prices'])) {
        $toolCards[] = ['team_site_prices', 'Website prices', 'Country sheets · publisher rates'];
    }
    if (!empty($toolSet['team_extracting'])) {
        $toolCards[] = ['team_extracting', 'Extracting sites', 'Sites list + Results + Push'];
    }
    if (!empty($toolSet['team_sites_emails'])) {
        $toolCards[] = ['team_sites_emails', 'Sites with emails', 'Add emails · Push to Admin'];
    }
    if (!empty($toolSet['team_admin_emails_search'])) {
        $toolCards[] = ['team_admin_emails_search', 'Admin emails search', 'Sites with emails - Admin · all countries'];
    }
    if (!empty($toolSet['team_email_campaigns'])) {
        $toolCards[] = ['team_email_campaigns', 'Campaign search', 'Email campaign sheets · all countries'];
    }
    if (!empty($toolSet['team_email_campaigns_drafts'])) {
        $toolCards[] = ['team_email_campaigns_drafts', 'Campaign drafts', 'Formatted outreach per project · copy for email'];
    }
    ?>
    <?php if ($toolCards && count($myDepartments) > 1): ?>
    <div class="card" style="margin-top:1rem">
      <h2>Your tools</h2>
      <div class="folders">
        <?php foreach ($toolCards as [$pageKey, $title, $hint]): ?>
          <a class="folder" href="index.php?page=<?= h($pageKey) ?>"
             data-dashboard-item data-search="<?= h(mb_strtolower($title . ' ' . $hint . ' open')) ?>">
            <h3><?= h($title) ?></h3>
            <p class="muted"><?= h($hint) ?></p>
            <?php folder_open_cue(); ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (count($myDepartments) > 1): ?>
    <div class="card" style="margin-top:1rem">
      <h2>Your departments</h2>
      <p class="muted">Tasks and assignment — tools are in the sidebar and Your tools above.</p>
      <div class="folders">
        <?php foreach ($myDepartments as $d):
            $stats = department_stats((int) $d['id']);
            ?>
          <a class="folder" href="index.php?page=team_departments&amp;folder=<?= urlencode((string) $d['slug']) ?>"
             data-dashboard-item
             data-search="<?= h(mb_strtolower((string) $d['name'] . ' ' . (int) $stats['open_tasks'] . ' open tasks assignment')) ?>">
            <h3><?= h((string) $d['name']) ?></h3>
            <p class="muted"><?= (int) $stats['open_tasks'] ?> open task<?= (int) $stats['open_tasks'] === 1 ? '' : 's' ?> · assignment</p>
            <?php folder_open_cue('Tasks'); ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php render_dashboard_help('team'); ?>
    <script>
(function () {
  var input = document.getElementById('dashboard-search');
  if (!input) return;
  var matchItems = [];
  var matchIndex = -1;
  var meta = document.querySelector('[data-dashboard-search-meta]');
  var emptyEl = document.querySelector('[data-dashboard-search-empty]');

  function clearHits() {
    document.querySelectorAll('.sheet-search-hit').forEach(function (el) {
      el.classList.remove('sheet-search-hit');
    });
  }

  function filterDashboard() {
    var q = String(input.value || '').trim().toLowerCase();
    var items = document.querySelectorAll('[data-dashboard-item]');
    var shown = 0;
    matchItems = [];
    clearHits();
    items.forEach(function (el) {
      var hay = String(el.getAttribute('data-search') || '').toLowerCase();
      var hit = !q || hay.indexOf(q) !== -1;
      el.hidden = !hit;
      if (hit) {
        shown++;
        if (q) matchItems.push(el);
      }
    });
    if (emptyEl) emptyEl.hidden = !(q && shown === 0);
    if (matchIndex >= matchItems.length) matchIndex = matchItems.length ? 0 : -1;
    if (meta) {
      if (q) {
        meta.hidden = false;
        if (!matchItems.length) {
          meta.textContent = '0 · Enter = next';
        } else if (matchIndex >= 0) {
          meta.textContent = (matchIndex + 1) + ' of ' + matchItems.length + ' · Enter = next';
        } else {
          meta.textContent = matchItems.length + (matchItems.length === 1 ? ' match' : ' matches')
            + ' · Enter = next';
        }
      } else {
        meta.hidden = true;
        meta.textContent = '';
        matchIndex = -1;
      }
    }
  }

  function jumpToMatch(dir) {
    var q = String(input.value || '').trim();
    if (!q) return;
    filterDashboard();
    if (!matchItems.length) return;
    if (matchIndex < 0) {
      matchIndex = dir > 0 ? 0 : matchItems.length - 1;
    } else {
      matchIndex = (matchIndex + dir + matchItems.length) % matchItems.length;
    }
    var el = matchItems[matchIndex];
    if (!el) return;
    clearHits();
    el.hidden = false;
    el.classList.add('sheet-search-hit');
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (meta) {
      meta.hidden = false;
      meta.textContent = (matchIndex + 1) + ' of ' + matchItems.length + ' · Enter = next';
    }
    window.setTimeout(function () {
      try { input.focus({ preventScroll: true }); } catch (err) { input.focus(); }
      try {
        var len = String(input.value || '').length;
        input.setSelectionRange(len, len);
      } catch (err2) {}
    }, 0);
  }

  var filterTimer = null;
  function scheduleFilterDashboard() {
    if (filterTimer) window.clearTimeout(filterTimer);
    var q = String(input.value || '').trim();
    if (!q) {
      filterTimer = null;
      filterDashboard();
      return;
    }
    filterTimer = window.setTimeout(function () {
      filterTimer = null;
      filterDashboard();
    }, 80);
  }

  input.addEventListener('input', function () {
    matchIndex = -1;
    scheduleFilterDashboard();
  });
  input.addEventListener('search', function () {
    matchIndex = -1;
    scheduleFilterDashboard();
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      jumpToMatch(e.shiftKey ? -1 : 1);
    }
  });
})();
(function () {
  document.addEventListener('stayajax:success', function (e) {
    var form = e.target;
    if (!form || !form.closest) return;
    var row = form.closest('tr[data-dashboard-item]');
    if (!row) return;
    var sel = form.querySelector('select[name="status"]');
    if (!sel) return;
    if (String(sel.value || '') === 'done') {
      row.remove();
      return;
    }
    var due = String(row.getAttribute('data-due') || '');
    var today = new Date();
    var ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    var overdue = (sel.value === 'open' || sel.value === 'in_progress') && due !== '' && due < ymd;
    row.classList.toggle('dept-task-overdue', overdue);
    var badge = row.querySelector('[data-overdue-badge]');
    if (overdue && !badge) {
      var strong = row.querySelector('td strong');
      if (strong) {
        var span = document.createElement('span');
        span.className = 'badge';
        span.setAttribute('data-overdue-badge', '');
        span.textContent = 'Overdue';
        strong.insertAdjacentElement('afterend', span);
        strong.insertAdjacentText('afterend', ' ');
      }
    } else if (!overdue && badge) {
      badge.remove();
    }
  });
})();
    </script>
    <?php
    render_footer('team');
    return;
}

// Admin viewing Team: every Team user is either waiting or assigned.
render_header('Dashboard', 'team');
?>
<div class="topbar">
  <div>
    <h1>Team dashboard</h1>
    <p class="muted">
      You are signed in as Admin. Team members see assigned work here after you add them to a department.
      Our database country lists stay private to Admin.
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_departments">My departments</a>
    <a class="btn secondary" href="index.php?page=admin_dashboard">Admin dashboard</a>
  </div>
</div>
<?php render_dashboard_help('team'); ?>
<?php
render_footer('team');

