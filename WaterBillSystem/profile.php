<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$u    = auth_user();
$pdo  = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'profile') {
        $fullName = trim((string)post('full_name', ''));
        $email    = trim((string)post('email', ''));
        $phone    = trim((string)post('phone', ''));

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET full_name=?, email=?, phone=? WHERE id=?');
            $stmt->execute([
                $fullName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $u['id'],
            ]);
            log_activity('UPDATE', 'Profile', 'Profile details updated');
            flash('success', 'Profile updated.');
            redirect('/pages/profile.php');
        }
    }

    if ($action === 'password') {
        $current = (string)post('current_password', '');
        $new     = (string)post('new_password', '');
        $confirm = (string)post('confirm_password', '');

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$u['id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 8)         $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm)        $errors[] = 'New passwords do not match.';

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            log_activity('UPDATE', 'Profile', 'Password changed');
            flash('success', 'Password changed successfully.');
            redirect('/pages/profile.php');
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$u['id']]);
$row = $stmt->fetch();

$__title = 'My Profile';
include __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header">Profile Information</div>
      <div class="card-body">
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input class="form-control" value="<?= e($row['username']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <input class="form-control text-capitalize" value="<?= e($row['role']) ?>" disabled>
            </div>
            <div class="col-md-12">
              <label class="form-label">Full Name</label>
              <input name="full_name" class="form-control" value="<?= e($row['full_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= e($row['email']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input name="phone" class="form-control" value="<?= e($row['phone']) ?>">
            </div>
          </div>
          <div class="mt-3">
            <button class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Change Password</div>
      <div class="card-body">
        <form method="post" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">
          <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required>
            <small class="text-muted">Minimum 8 characters.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
          </div>
          <button class="btn btn-primary w-100">Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>