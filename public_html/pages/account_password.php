<?php
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) post('current_password');
    $new = (string) post('new_password');
    $confirm = (string) post('confirm_password');
    if ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $error = change_user_password((int) $user['id'], $current, $new);
        if ($error === '') {
            flash('ok', 'Password updated.');
            if (is_admin()) {
                redirect('index.php?page=admin_dashboard');
            }
            redirect(team_home_url());
        }
    }
}

$forced = user_must_change_password($user);
$panel = is_admin() ? 'admin' : 'team';
$home = is_admin() ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard';
render_header('Change password', $panel);
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => $home],
    ['label' => 'Change password'],
]); ?>
<div class="topbar">
  <div>
    <h1>Change password</h1>
    <p class="muted">
      <?= $forced
          ? 'Your account still uses a demo or weak password. Set a new one to continue.'
          : 'Update the password for ' . h((string) $user['username']) . '.' ?>
    </p>
  </div>
</div>

<div class="card" style="max-width:28rem">
  <?php if ($error): render_alert_box('error', $error); endif; ?>
  <form method="post" action="index.php?page=account_password" autocomplete="off">
    <?= csrf_field() ?>
    <label>Current password</label>
    <input type="password" name="current_password" required autofocus>
    <label>New password (min 8 characters)</label>
    <input type="password" name="new_password" required minlength="8">
    <label>Confirm new password</label>
    <input type="password" name="confirm_password" required minlength="8">
    <p style="margin-top:1.1rem">
      <button class="btn" type="submit">Save new password</button>
      <?php if (!$forced): ?>
        <a class="btn secondary" href="index.php?page=<?= is_admin()
            ? 'admin_dashboard'
            : 'team_dashboard' ?>">Cancel</a>
      <?php endif; ?>
    </p>
  </form>
</div>
<?php render_footer($panel); ?>
