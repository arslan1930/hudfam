<?php
/**
 * Admin account: email verify + change / email-reset password.
 */
$user = require_admin();
ensure_account_schema();
$row = load_user_by_id((int) $user['id']);
if (!$row) {
    flash('error', 'Account not found.');
    redirect('index.php?page=login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');

    if ($action === 'save_email') {
        $email = trim((string) post('email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
        } else {
            $old = trim((string) ($row['email'] ?? ''));
            if (strcasecmp($old, $email) !== 0) {
                db()->prepare('UPDATE users SET email=?, email_verified_at=NULL WHERE id=? AND role=\'admin\'')
                    ->execute([$email, (int) $user['id']]);
                flash('ok', $email === '' ? 'Email cleared.' : 'Email saved. Send a verification link next.');
            } else {
                flash('ok', 'Email unchanged.');
            }
        }
        redirect('index.php?page=admin_account');
    }

    if ($action === 'send_verify') {
        $row = load_user_by_id((int) $user['id']) ?: $row;
        $result = send_admin_email_verification($row);
        if ($result['ok']) {
            flash('ok', 'Verification email sent to ' . $row['email'] . '.');
        } else {
            flash('error', $result['error']);
        }
        redirect('index.php?page=admin_account');
    }

    if ($action === 'send_reset') {
        $row = load_user_by_id((int) $user['id']) ?: $row;
        $result = request_admin_password_reset((string) ($row['email'] ?? ''));
        if (!$result['ok']) {
            flash('error', $result['error'] ?: 'Could not send reset email.');
        } else {
            flash('ok', 'Password reset link sent to your verified email.');
        }
        redirect('index.php?page=admin_account');
    }

    if ($action === 'change_password') {
        $current = (string) post('current_password');
        $password = (string) post('password');
        $confirm = (string) post('password2');
        if (!password_verify($current, (string) $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($password) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($password !== $confirm) {
            flash('error', 'New passwords do not match.');
        } else {
            set_user_password((int) $user['id'], $password);
            flash('ok', 'Password changed.');
        }
        redirect('index.php?page=admin_account');
    }
}

$row = load_user_by_id((int) $user['id']) ?: $row;
$verified = admin_email_is_verified($row);

render_header('Account', 'admin');
?>
<?php render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => 'index.php?page=admin_dashboard'],
    ['label' => 'Account'],
]); ?>
<div class="topbar">
  <div>
    <h1>Admin account</h1>
    <p class="muted">Verify your email to enable Forgot password. Only Admin can reset via email — Team passwords are set by Admin on Users.</p>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <h2>Email</h2>
    <p class="help">Status:
      <?php if (trim((string) $row['email']) === ''): ?>
        <strong>No email</strong>
      <?php elseif ($verified): ?>
        <strong style="color:var(--ok)">Verified</strong> · <?= h(substr((string) $row['email_verified_at'], 0, 16)) ?>
      <?php else: ?>
        <strong style="color:var(--warn)">Not verified</strong>
      <?php endif; ?>
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_email">
      <label>Admin email</label>
      <input type="email" name="email" value="<?= h((string) $row['email']) ?>" placeholder="you@company.com">
      <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Save email</button></p>
    </form>
    <?php if (trim((string) $row['email']) !== '' && !$verified): ?>
      <form method="post" style="margin-top:0.75rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_verify">
        <button class="btn secondary" type="submit">Send verification email</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Password</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <label>Current password</label>
      <input type="password" name="current_password" required autocomplete="current-password">
      <label>New password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
      <label>Confirm new password</label>
      <input type="password" name="password2" required minlength="8" autocomplete="new-password">
      <p class="actions" style="margin-top:1rem"><button class="btn" type="submit">Change password</button></p>
    </form>
    <hr style="border:0;border-top:1px solid var(--line);margin:1.2rem 0">
    <h3 style="margin:0 0 0.4rem;font-size:1rem">Forgot current password?</h3>
    <?php if ($verified): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_reset">
        <p class="help">Sends a 2-hour reset link to <?= h((string) $row['email']) ?>.</p>
        <button class="btn secondary" type="submit">Email me a reset link</button>
      </form>
    <?php else: ?>
      <p class="help">Verify your email first, then you can request a reset link (also available from the login page).</p>
    <?php endif; ?>
  </div>
</div>
<?php render_footer('admin'); ?>
