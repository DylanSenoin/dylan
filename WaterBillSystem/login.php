<?php
require_once __DIR__ . '/includes/init.php';
if (is_logged_in()) { redirect('/pages/dashboard.php'); }
$error = null; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)post('username', ''));
    $password = (string)post('password', '');
    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]); $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account has been deactivated.';
        } else {
            login_user($user);
            log_activity('LOGIN', 'Authentication', 'User logged in: ' . $user['username']);
            redirect('/pages/dashboard.php');
        }
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('/assets/css/style.css')) ?>" rel="stylesheet">
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">
    <i class="bi bi-droplet-fill"></i>
    <div><h1><?= e(setting('provider_name', APP_NAME)) ?></h1><p>Water Bill Management System</p></div>
  </div>
  <?php foreach (get_flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?> py-2"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Username</label>
      <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span><input type="text" name="username" class="form-control" value="<?= e($username) ?>" required autofocus></div>
    </div>
    <div class="mb-4">
      <label class="form-label">Password</label>
      <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span><input type="password" name="password" id="password" class="form-control" required><button class="btn btn-outline-secondary" type="button" id="togglePass" tabindex="-1"><i class="bi bi-eye"></i></button></div>
    </div>
    <button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In</button>
  </form>
</div>
<script>
document.getElementById('togglePass').addEventListener('click', function () {
  const p = document.getElementById('password');
  p.type = p.type === 'password' ? 'text' : 'password';
  this.querySelector('i').classList.toggle('bi-eye'); this.querySelector('i').classList.toggle('bi-eye-slash');
});
</script>
</body></html>