<?php
/**
 * Verify Admin email from link.
 */
ensure_account_schema();
$token = trim((string) get('token'));
$hit = $token !== '' ? consume_auth_token($token, 'email_verify', true) : null;
$ok = $hit && ($hit['user']['role'] ?? '') === 'admin';

if ($ok) {
    mark_admin_email_verified((int) $hit['user']['id']);
    if (current_user() && (int) current_user()['id'] === (int) $hit['user']['id']) {
        flash('ok', 'Email verified. You can use Forgot password with this address.');
        redirect('index.php?page=admin_account');
    }
}

$app = app_config()['app_name'] ?? 'TechxForm';
render_header('Verify email');
?>
<div class="login-wrap">
  <div class="login-card">
    <h1>Verify Admin email</h1>
    <?php if ($ok): ?>
      <ul class="messages"><li>Email verified for <strong><?= h($hit['user']['username']) ?></strong>.</li></ul>
      <p><a class="btn" href="index.php?page=login">Sign in</a></p>
    <?php else: ?>
      <ul class="messages"><li class="error">This verification link is invalid or expired.</li></ul>
      <p class="muted">Sign in as Admin and request a new verification email from Account.</p>
      <p><a class="btn" href="index.php?page=login">Sign in</a></p>
    <?php endif; ?>
  </div>
  <?php render_project_credit(); ?>
</div>
<?php render_footer(); ?>
