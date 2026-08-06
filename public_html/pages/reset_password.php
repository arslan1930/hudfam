<?php
/**
 * Set a new Admin password from email reset token.
 */
ensure_account_schema();
$token = trim((string) (post('token') ?: get('token')));
$error = '';
$done = false;
$preview = $token !== '' ? consume_auth_token($token, 'password_reset', false) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) post('password');
    $confirm = (string) post('password2');
    $hit = consume_auth_token($token, 'password_reset', false);
    if (!$hit || ($hit['user']['role'] ?? '') !== 'admin') {
        $error = 'This reset link is invalid or expired. Request a new one.';
        $preview = null;
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hit = consume_auth_token($token, 'password_reset', true);
        if (!$hit || ($hit['user']['role'] ?? '') !== 'admin') {
            $error = 'This reset link is invalid or expired.';
        } else {
            set_user_password((int) $hit['user']['id'], $password);
            $done = true;
            flash('ok', 'Admin password updated. Sign in with your new password.');
            redirect('index.php?page=login');
        }
    }
}

$app = app_config()['app_name'] ?? 'TechxForm';
render_header('Reset password');
?>
<div class="login-wrap">
  <div class="login-card">
    <h1>Reset Admin password</h1>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <?php if (!$preview || ($preview['user']['role'] ?? '') !== 'admin'): ?>
      <p class="muted">This link is invalid or expired.</p>
      <p><a class="btn" href="index.php?page=forgot_password">Request a new link</a></p>
    <?php else: ?>
      <p class="muted">Setting a new password for <strong><?= h($preview['user']['username']) ?></strong>.</p>
      <form method="post">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <label>New password</label>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
        <label>Confirm password</label>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password">
        <p style="margin-top:1.1rem"><button class="btn" type="submit">Save password</button></p>
      </form>
    <?php endif; ?>
    <p class="help" style="margin-top:1rem"><a href="index.php?page=login">Back to sign in</a></p>
  </div>
</div>
<?php render_footer(); ?>
