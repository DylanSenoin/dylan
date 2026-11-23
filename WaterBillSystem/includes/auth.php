<?php
declare(strict_types=1);

function auth_user(bool $reset = false): ?array
{
    static $resolved = false;
    static $user     = null;

    if ($reset) {
        $resolved = false;
        $user     = null;
        return null;
    }

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, username, full_name, email, role, customer_id, status
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        logout_user();
        return null;
    }
    return $user = $row;
}

function is_logged_in(): bool { return auth_user() !== null; }

function has_role(string ...$roles): bool
{
    $u = auth_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['last_seen']  = time();
    auth_user(true);
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    auth_user(true);
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        redirect('/login.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        include __DIR__ . '/forbidden.php';
        exit;
    }
}