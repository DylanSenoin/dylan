<?php
declare(strict_types=1);

$u        = auth_user();
$role     = $u['role'] ?? 'guest';
$current  = basename($_SERVER['PHP_SELF'], '.php');

$menu = [
    ['label' => 'Dashboard',          'icon' => 'speedometer2',      'href' => '/pages/dashboard.php',   'roles' => ['admin','staff','customer'], 'matches' => ['dashboard']],
    ['label' => 'Customers',          'icon' => 'people',            'href' => '/pages/customers.php',   'roles' => ['admin','staff'],            'matches' => ['customers','customer-form','customer-view']],
    ['label' => 'Meter Readings',     'icon' => 'speedometer',       'href' => '/pages/readings.php',    'roles' => ['admin','staff'],            'matches' => ['readings','reading-form']],
    ['label' => 'Water Billing',      'icon' => 'receipt',           'href' => '/pages/bills.php',       'roles' => ['admin','staff'],            'matches' => ['bills','bill-generate','bill-view']],
    ['label' => 'Payments',           'icon' => 'cash-coin',         'href' => '/pages/payments.php',    'roles' => ['admin','staff'],            'matches' => ['payments','payment-form','receipt']],
    ['label' => 'Billing Statements', 'icon' => 'file-earmark-text', 'href' => '/pages/statements.php',  'roles' => ['admin','staff'],            'matches' => ['statements','statement-view','statement-pdf']],
    ['label' => 'My Bills',           'icon' => 'file-earmark-text', 'href' => '/pages/my-bills.php',    'roles' => ['customer'],                 'matches' => ['my-bills','my-bill-view']],
    ['label' => 'Reports',            'icon' => 'graph-up-arrow',    'href' => '/pages/reports.php',     'roles' => ['admin'],                    'matches' => ['reports','report-export']],
    ['label' => 'User Management',    'icon' => 'person-gear',       'href' => '/pages/users.php',       'roles' => ['admin'],                    'matches' => ['users','user-form']],
    ['label' => 'Settings',           'icon' => 'sliders',           'href' => '/pages/settings.php',    'roles' => ['admin'],                    'matches' => ['settings']],
];
?>
<aside class="wb-sidebar" id="wbSidebar">
  <div class="brand">
    <i class="bi bi-droplet-fill"></i>
    <span><?= e(setting('provider_name', APP_NAME)) ?></span>
  </div>

  <nav class="nav flex-column py-2 flex-grow-1">
    <?php foreach ($menu as $item):
        if (!in_array($role, $item['roles'], true)) continue;
        $active = in_array($current, $item['matches'], true) ? 'active' : '';
    ?>
      <a class="nav-link <?= $active ?>" href="<?= e(url($item['href'])) ?>">
        <i class="bi bi-<?= e($item['icon']) ?>"></i>
        <span><?= e($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <a class="nav-link wb-logout py-3" href="<?= e(url('/logout.php')) ?>">
    <i class="bi bi-box-arrow-right"></i><span>Logout</span>
  </a>
</aside>