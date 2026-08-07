<?php
if (current_user()) {
    if (is_admin()) {
        redirect('index.php?page=admin_dashboard');
    }
    $u = current_user();
    redirect(
        user_is_department_scoped($u)
            ? 'index.php?page=team_departments'
            : 'index.php?page=team_dashboard'
    );
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attempt_login(trim(post('username')), (string) post('password'))) {
        if (user_must_change_password()) {
            flash('error', 'Change your password before continuing.');
            redirect('index.php?page=account_password');
        }
        if (is_admin()) {
            redirect('index.php?page=admin_dashboard');
        }
        $u = current_user();
        redirect(
            user_is_department_scoped($u)
                ? 'index.php?page=team_departments'
                : 'index.php?page=team_dashboard'
        );
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
    <?php if ($error): render_alert_box('error', $error); endif; ?>
    <form method="post">
      <label>Username</label>
      <input type="text" name="username" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
  </div>
  <?php render_project_credit(); ?>
</div>
<?php render_footer(); ?>
