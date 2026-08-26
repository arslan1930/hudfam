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
    $userName = trim(post('username'));
    $password = (string) post('password');
    if (login_throttle_blocked($userName)) {
        $error = 'Too many sign-in attempts. Wait a few minutes and try again.';
    } elseif (attempt_login($userName, $password)) {
        login_throttle_clear($userName);
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
    } else {
        login_throttle_note_failure($userName);
        $error = 'Invalid username or password.';
    }
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
    <p class="muted">Shared site database — Admin manages Our database; Team filters and adds unique sites.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label for="login_username">Username</label>
      <input id="login_username" type="text" name="username" required autofocus
             autocomplete="username"
             placeholder="username"
             <?= $error ? 'aria-invalid="true" aria-describedby="login_error"' : '' ?>>
      <p class="help" style="margin:0.25rem 0 0">Admin can also sign in with their account email.</p>
      <label for="login_password">Password</label>
      <input id="login_password" type="password" name="password" required autocomplete="current-password"
             <?= $error ? 'aria-invalid="true" aria-describedby="login_error"' : '' ?>>
      <?php if ($error): ?><p id="login_error" class="visually-hidden"><?= h($error) ?></p><?php endif; ?>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
    <p class="help" style="margin-top:1rem">
      <a href="index.php?page=forgot_password">Forgot password?</a>
      <span class="muted"> (Admin only · verified email)</span>
    </p>
  </div>
  <?php render_project_credit(); ?>
</div>
<?php render_footer(); ?>
