<?php
require_once __DIR__ . '/includes/init.php';
$adminExists = (bool) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($adminExists) {
    exit('<h3 style="font-family:sans-serif">Setup already completed. Delete <code>setup.php</code> for security.</h3>');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim((string)post('full_name', ''));
    $username = trim((string)post('username', ''));
    $email    = trim((string)post('email', ''));
    $password = (string)post('password', '');
    $confirm  = (string)post('confirm', '');

    if ($fullName === '' || $username === '') $errors[] = 'Full name and username are required.';
    if (strlen($username) < 4)                  $errors[] = 'Username must be at least 4 characters.';
    if (strlen($password) < 8)                  $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)                 $errors[] = 'Passwords do not match.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, email, role, status) VALUES (?, ?, ?, ?, "admin", "active")');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email !== '' ? $email : null]);
        exit('<h3 style="font-family:sans-serif;color:#0d6efd">Administrator created successfully.<br><a href="' . e(url('/login.php')) . '">Go to Login</a> — then delete <code>setup.php</code>.</h3>');
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Initial Setup — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('/assets/css/style.css')) ?>" rel="stylesheet">
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">
    <i class="bi bi-droplet-fill"></i>
    <div><h1>Initial Setup</h1><p>Create the first administrator account</p></div>
  </div>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Full Name</label><input name="full_name" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Email (optional)</label><input type="email" name="email" class="form-control"></div>
    <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
    <div class="mb-4"><label class="form-label">Confirm Password</label><input type="password" name="confirm" class="form-control" required></div>
    <button class="btn btn-primary w-100 py-2">Create Administrator</button>
  </form>
</div>
</body></html>