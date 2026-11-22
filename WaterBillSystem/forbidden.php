<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>403 — Access Denied</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light d-grid" style="min-height:100vh;place-items:center">
  <div class="text-center p-5 bg-white rounded-3 shadow-sm" style="max-width:440px">
    <div class="display-3 text-primary"><i class="bi bi-shield-lock"></i></div>
    <h1 class="h4 mt-3">Access Denied</h1>
    <p class="text-muted mb-4">Your account does not have permission to view this page.</p>
    <a href="<?= e(url('/pages/dashboard.php')) ?>" class="btn btn-primary">Back to Dashboard</a>
  </div>
</body></html>