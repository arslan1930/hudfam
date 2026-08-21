<?php
/**
 * Admin-only forgot password (by verified email).
 */
ensure_account_schema();
if (current_user()) {
    redirect(is_admin() ? 'index.php?page=admin_account' : 'index.php?page=team_dashboard');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) post('email'));
    $result = request_admin_password_reset($email);
    if (!$result['ok']) {
        $error = $result['error'] ?: 'Could not start password reset.';
    } else {
        $message = 'If that email belongs to a verified Admin account, a reset link was sent. Check your inbox (and spam).';
    }
}

$app = app_config()['app_name'] ?? 'TechxForm';
render_header('Forgot password');
?>
<div class="login-wrap">
  <div class="login-card">
    <h1>Forgot password</h1>
    <p class="muted">Admin only — reset using your <strong>verified</strong> Admin email. Team members must ask Admin to set a new password.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <?php if ($message): ?><ul class="messages"><li><?= h($message) ?></li></ul><?php endif; ?>
    <?php if (!$message): ?>
    <form method="post">
      <?= csrf_field() ?>
      <label>Admin email</label>
      <input type="email" name="email" required autofocus placeholder="you@company.com">
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Send reset link</button></p>
    </form>
    <?php endif; ?>
    <p class="help" style="margin-top:1rem">
      <a href="index.php?page=login">Back to sign in</a>
      · Need to verify email first? Sign in, then open <strong>Account</strong>.
    </p>
  </div>
</div>
<?php render_footer(); ?>
