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
render_header('Login');
?>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= h(app_config()['app_name'] ?? 'Hudfam') ?></h1>
    <p class="muted">Linkbuilding inventory &amp; project folders.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <form method="post">
      <label>Username</label>
      <input type="text" name="username" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
  </div>
</div>
<?php render_footer(); ?>
