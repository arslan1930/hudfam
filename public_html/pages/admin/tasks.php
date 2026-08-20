<?php
/**
 * Legacy Admin tasks — redirected to Departments (source of truth).
 * Old team_tasks rows are not migrated.
 */
$user = require_admin();
flash('ok', 'Use Departments to assign team work. The old Assign tasks page is retired.');
redirect('index.php?page=admin_departments');
