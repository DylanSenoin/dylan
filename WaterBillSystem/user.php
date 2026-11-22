<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$me  = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $targetId  = (int)post('user_id', 0);
    $newStatus = post('action') === 'activate' ? 'active' : 'inactive';

    if ($targetId === (int)$me['id']) {
        flash('danger', 'You cannot change the status of your own account.');
        redirect('/pages/users.php');
    }

    $stmt = $pdo->prepare('SELECT role, username FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('danger', 'User not found.');
        redirect('/pages/users.php');
    }

    if ($target['role'] === 'admin' && $newStatus === 'inactive') {
        $activeAdmins = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'"
        )->fetchColumn();
        if ($activeAdmins <= 1) {
            flash('danger', 'Cannot deactivate the last active administrator.');
            redirect('/pages/users.php');
        }
    }

    $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
    $stmt->execute([$newStatus, $targetId]);
    log_activity('UPDATE', 'Users', "Set user '{$target['username']}' to $newStatus");
    flash('success', "User {$target['username']} is now $newStatus.");
    redirect('/pages/users.php');
}

$where  = ['1=1'];
$params = [];

$q = trim((string)get('q', ''));
if ($q !== '') {
    $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$role = (string)get('role', '');
if (in_array($role, ['admin', 'staff', 'customer'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
$status = (string)get('status', '');
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT u.*, c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name
        FROM users u
        LEFT JOIN customers c ON c.id = u.customer_id
        WHERE $whereSql
        ORDER BY u.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$__title = 'User Management';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>All Users</span>
    <a href="<?= e(url('/pages/user-form.php')) ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-person-plus me-1"></i> Create User
    </a>
  </div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Search username, name, email…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-2">
        <select name="role" class="form-select form-select-sm">
          <option value="">All Roles</option>
          <?php foreach (['admin','staff','customer'] as $r): ?>
            <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Filter</button>
        <a href="<?= e(url('/pages/users.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Username</th><th>Full Name</th><th>Role</th><th>Linked Customer</th>
        <th>Last Login</th><th>Status</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No users match your filters.</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td class="small fw-semibold"><?= e($u['username']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="badge text-bg-primary">you</span>' : '' ?></td>
          <td class="small"><?= e($u['full_name']) ?><br><span class="text-muted"><?= e($u['email'] ?: '') ?></span></td>
          <td>
            <span class="badge text-capitalize text-bg-<?= match($u['role']) {
              'admin' => 'primary', 'staff' => 'info', default => 'light'
            } ?> <?= $u['role'] === 'customer' ? 'text-dark border' : '' ?>">
              <?= e($u['role']) ?>
            </span>
          </td>
          <td class="small"><?= $u['customer_name'] ? e($u['customer_name']) . ' <span class="text-muted">(' . e($u['customer_code']) . ')</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="small text-muted"><?= $u['last_login'] ? e(date('M j, Y', strtotime($u['last_login']))) : 'Never' ?></td>
          <td>
            <span class="badge <?= $u['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?> text-capitalize">
              <?= e($u['status']) ?>
            </span>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary py-0" href="<?= e(url('/pages/user-form.php?id=' . (int)$u['id'])) ?>">Edit</a>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Change status of this user?')">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                <button class="btn btn-sm py-0 <?= $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                  <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="card-footer bg-white">
    <ul class="pagination pagination-sm mb-0 justify-content-center">
      <?php for ($p = 1; $p <= $pages; $p++):
        $query = $_GET; $query['page'] = $p; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query($query)) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>