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
      <label for="login_username">Username</label>
      <input id="login_username" type="text" name="username" required autofocus autocomplete="username"
             <?= $error ? 'aria-invalid="true" aria-describedby="login_error"' : '' ?>>
      <label for="login_password">Password</label>
      <input id="login_password" type="password" name="password" required autocomplete="current-password"
             <?= $error ? 'aria-invalid="true" aria-describedby="login_error"' : '' ?>>
      <?php if ($error): ?><p id="login_error" class="visually-hidden"><?= h($error) ?></p><?php endif; ?>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
  </div>
  <?php render_project_credit(); ?>
</div>
<?php render_footer(); ?>
