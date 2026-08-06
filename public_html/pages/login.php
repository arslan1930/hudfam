<?php
if (current_user()) {
    redirect(is_admin() ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attempt_login(trim(post('username')), (string) post('password'))) {
        redirect(is_admin() ? 'index.php?page=admin_dashboard' : 'index.php?page=team_dashboard');
    }
    $error = 'Invalid username or password.';
}
$app = app_config()['app_name'] ?? 'TechxForm';
render_header('Login');
?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <img class="brand-logo" src="<?= h(brand_logo_url()) ?>" alt="<?= h($app) ?>">
      <h1><?= h($app) ?></h1>
    </div>
    <p class="muted">Shared URL database — Admin adds sites, Team filters and adds unique ones.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <form method="post">
      <label>Username</label>
      <input type="text" name="username" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
    <p class="help" style="margin-top:1rem">
      <a href="index.php?page=forgot_password">Forgot password?</a>
      <span class="muted"> (Admin only · verified email)</span>
    </p>
  </div>
</div>
<?php render_footer(); ?>
