<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo    = db();
$id     = (int)get('id', 0);
$isEdit = $id > 0;
$errors = [];

$customer = [
    'customer_code'   => '',
    'first_name'      => '',
    'last_name'       => '',
    'email'           => '',
    'phone'           => '',
    'address'         => '',
    'barangay'        => '',
    'city'            => '',
    'meter_number'    => '',
    'connection_type' => 'residential',
    'status'          => 'active',
];

$createLogin = false;
$loginUsername = '';

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('danger', 'Customer not found.');
        redirect('/pages/customers.php');
    }
    $customer = $existing;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customer['first_name']      = trim((string)post('first_name', ''));
    $customer['last_name']       = trim((string)post('last_name', ''));
    $customer['email']           = trim((string)post('email', ''));
    $customer['phone']           = trim((string)post('phone', ''));
    $customer['address']         = trim((string)post('address', ''));
    $customer['barangay']        = trim((string)post('barangay', ''));
    $customer['city']            = trim((string)post('city', ''));
    $customer['meter_number']    = trim((string)post('meter_number', ''));
    $customer['connection_type'] = (string)post('connection_type', 'residential');
    $customer['status']          = (string)post('status', 'active');

    if ($customer['first_name'] === '')   $errors[] = 'First name is required.';
    if ($customer['last_name'] === '')    $errors[] = 'Last name is required.';
    if ($customer['address'] === '')      $errors[] = 'Address is required.';
    if ($customer['meter_number'] === '') $errors[] = 'Meter number is required.';
    if ($customer['email'] !== '' && !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (!in_array($customer['connection_type'], ['residential', 'commercial', 'government'], true)) {
        $errors[] = 'Invalid connection type.';
    }
    if (!in_array($customer['status'], ['active', 'inactive'], true)) {
        $errors[] = 'Invalid status.';
    }

    if ($customer['meter_number'] !== '') {
        $sql    = 'SELECT id FROM customers WHERE meter_number = ?';
        $params = [$customer['meter_number']];
        if ($isEdit) {
            $sql .= ' AND id <> ?';
            $params[] = $id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            $errors[] = 'That meter number is already assigned to another customer.';
        }
    }

    if (!$isEdit) {
        $createLogin   = post('create_login') === '1';
        $loginUsername = trim((string)post('login_username', ''));
        $loginPassword = (string)post('login_password', '');

        if ($createLogin) {
            if (strlen($loginUsername) < 4) {
                $errors[] = 'Portal username must be at least 4 characters.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$loginUsername]);
                if ($stmt->fetch()) {
                    $errors[] = 'That portal username is already taken.';
                }
            }
            if (strlen($loginPassword) < 8) {
                $errors[] = 'Portal password must be at least 8 characters.';
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    'UPDATE customers
                     SET first_name = ?, last_name = ?, email = ?, phone = ?,
                         address = ?, barangay = ?, city = ?, meter_number = ?,
                         connection_type = ?, status = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $customer['first_name'],
                    $customer['last_name'],
                    $customer['email'] !== '' ? $customer['email'] : null,
                    $customer['phone'] !== '' ? $customer['phone'] : null,
                    $customer['address'],
                    $customer['barangay'] !== '' ? $customer['barangay'] : null,
                    $customer['city'] !== '' ? $customer['city'] : null,
                    $customer['meter_number'],
                    $customer['connection_type'],
                    $customer['status'],
                    $id,
                ]);
                log_activity('UPDATE', 'Customers',
                    "Updated customer {$customer['customer_code']} ({$customer['first_name']} {$customer['last_name']})");
                $pdo->commit();
                flash('success', 'Customer updated.');
                redirect('/pages/customer-view.php?id=' . $id);
            } else {
                $code = next_customer_code();
                $stmt = $pdo->prepare(
                    'INSERT INTO customers
                        (customer_code, first_name, last_name, email, phone,
                         address, barangay, city, meter_number, connection_type, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $code,
                    $customer['first_name'],
                    $customer['last_name'],
                    $customer['email'] !== '' ? $customer['email'] : null,
                    $customer['phone'] !== '' ? $customer['phone'] : null,
                    $customer['address'],
                    $customer['barangay'] !== '' ? $customer['barangay'] : null,
                    $customer['city'] !== '' ? $customer['city'] : null,
                    $customer['meter_number'],
                    $customer['connection_type'],
                    $customer['status'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                if ($createLogin) {
                    $uStmt = $pdo->prepare(
                        'INSERT INTO users
                            (username, password_hash, full_name, email, phone, role, customer_id, status)
                         VALUES (?,?,?,?,?,"customer",?,?)'
                    );
                    $uStmt->execute([
                        $loginUsername,
                        password_hash($loginPassword, PASSWORD_DEFAULT),
                        $customer['first_name'] . ' ' . $customer['last_name'],
                        $customer['email'] !== '' ? $customer['email'] : null,
                        $customer['phone'] !== '' ? $customer['phone'] : null,
                        $newId,
                        $customer['status'],
                    ]);
                }

                log_activity('CREATE', 'Customers',
                    "Registered customer $code ({$customer['first_name']} {$customer['last_name']})"
                    . ($createLogin ? " with login '$loginUsername'" : ''));
                $pdo->commit();
                flash('success', "Customer {$code} registered successfully.");
                redirect('/pages/customer-view.php?id=' . $newId);
            }
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Save failed: ' . $ex->getMessage();
        }
    }
}

$__title = $isEdit ? 'Edit Customer' : 'Add Customer';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <a href="<?= e(url('/pages/customers.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Customers
  </a>
  <?php if ($isEdit): ?>
    <span class="badge text-bg-light border"><?= e($customer['customer_code']) ?></span>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" novalidate autocomplete="off">
  <?= csrf_field() ?>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-person me-2"></i>Customer Information</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">First Name <span class="text-danger">*</span></label>
          <input name="first_name" class="form-control" value="<?= e($customer['first_name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Last Name <span class="text-danger">*</span></label>
          <input name="last_name" class="form-control" value="<?= e($customer['last_name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= e($customer['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input name="phone" class="form-control" value="<?= e($customer['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Street / House Address <span class="text-danger">*</span></label>
          <input name="address" class="form-control" value="<?= e($customer['address']) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Barangay</label>
          <input name="barangay" class="form-control" value="<?= e($customer['barangay'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">City / Municipality</label>
          <input name="city" class="form-control" value="<?= e($customer['city'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-speedometer2 me-2"></i>Meter & Connection</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Meter Number <span class="text-danger">*</span></label>
          <input name="meter_number" class="form-control" value="<?= e($customer['meter_number']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Connection Type</label>
          <select name="connection_type" class="form-select">
            <?php foreach (['residential', 'commercial', 'government'] as $t): ?>
              <option value="<?= $t ?>" <?= $customer['connection_type'] === $t ? 'selected' : '' ?>>
                <?= ucfirst($t) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Account Status</label>
          <select name="status" class="form-select">
            <option value="active"   <?= $customer['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$isEdit): ?>
  <div class="card mb-3">
    <div class="card-header">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" name="create_login" id="createLoginCheck"
               value="1" <?= $createLogin ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="createLoginCheck">
          Create customer portal login (optional)
        </label>
      </div>
    </div>
    <div class="card-body" id="loginFieldsWrap" style="<?= $createLogin ? '' : 'display:none' ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Username <span class="text-danger">*</span></label>
          <input name="login_username" class="form-control" minlength="4" value="<?= e($loginUsername) ?>">
          <small class="text-muted">Minimum 4 characters.</small>
        </div>
        <div class="col-md-6">
          <label class="form-label">Password <span class="text-danger">*</span></label>
          <input type="password" name="login_password" class="form-control" minlength="8">
          <small class="text-muted">Minimum 8 characters.</small>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4">
      <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Register Customer' ?>
    </button>
    <a href="<?= e(url('/pages/customers.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php if (!$isEdit): ?>
<script>
(function () {
  const chk  = document.getElementById('createLoginCheck');
  const wrap = document.getElementById('loginFieldsWrap');
  if (chk && wrap) {
    chk.addEventListener('change', () => {
      wrap.style.display = chk.checked ? '' : 'none';
    });
  }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
