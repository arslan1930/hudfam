<?php
if (current_user()) {
    if (is_admin()) {
        redirect('index.php?page=admin_dashboard');
    }
    redirect(team_home_url());
}
$error = '';
$userName = '';
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
        redirect(team_home_url());
    } else {
        login_throttle_note_failure($userName);
        $error = 'Invalid username or password.';
    }
}
$app = app_config()['app_name'] ?? 'TechxForm';
$mailReady = function_exists('app_mail_reset_is_ready') && app_mail_reset_is_ready();
render_header('Login');
?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <img class="brand-logo" src="<?= h(brand_logo_url()) ?>" alt="<?= h($app) ?>">
      <h1><?= h($app) ?></h1>
    </div>
    <p class="muted">Sign in to <?= h($app) ?>.</p>
    <?php if ($error): ?><ul class="messages"><li class="error"><?= h($error) ?></li></ul><?php endif; ?>
    <?php if ($error): ?>
      <p class="help" id="login_help" style="margin:0 0 0.85rem">
        Check username (or Admin email) and password.
        Admins: Forgot password needs a verified email<?= $mailReady ? '' : ' — mail may not be set on this server yet' ?>.
        Team: ask Admin to set a new password on Users.
      </p>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label for="login_username">Username or Admin email</label>
      <input id="login_username" type="text" name="username" required
             autocomplete="username"
             placeholder="admin or you@company.com"
             value="<?= h($userName) ?>"
             <?= $error ? 'aria-invalid="true" aria-describedby="login_error login_help"' : 'autofocus' ?>>
      <p class="help" style="margin:0.25rem 0 0">Team uses username. Admin can also use their account email.</p>
      <label for="login_password">Password</label>
      <input id="login_password" type="password" name="password" required autocomplete="current-password"
             <?= $error ? 'autofocus aria-invalid="true" aria-describedby="login_error login_help"' : '' ?>>
      <?php if ($error): ?><p id="login_error" class="visually-hidden"><?= h($error) ?></p><?php endif; ?>
      <p style="margin-top:1.1rem"><button class="btn" type="submit">Sign in</button></p>
    </form>
    <p class="help" style="margin-top:1rem">
      <a href="index.php?page=forgot_password">Forgot password?</a>
      <?php if ($mailReady): ?>
        <span class="muted"> (Admin only · verified email)</span>
      <?php else: ?>
        <span class="muted"> (Admin only · verified email. Mail may not send until mail_from / SMTP is set.)</span>
      <?php endif; ?>
    </p>
    <p class="help" style="margin:0.35rem 0 0">Team cannot reset here — Admin sets Team passwords on Users.</p>
  </div>
  <?php render_project_credit(); ?>
</div>
<?php render_footer(); ?>
