<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('gacha_admin');
    session_start();
}

function auth_login(string $username, string $password, bool $remember = false): bool {
    $pdo = gacha_pdo();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if (!$admin) return false;
    if (!password_verify($password, $admin['password_hash'])) return false;

    auth_start();
    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['username'];
    $_SESSION['logged_at']  = time();
    $_SESSION['remember']   = $remember;

    if ($remember) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + 30 * 86400,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return true;
}

function auth_logout(): void {
    auth_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_check(): bool {
    auth_start();
    if (empty($_SESSION['admin_id'])) return false;
    $max = !empty($_SESSION['remember']) ? 30 * 86400 : 7 * 86400;
    if (time() - ($_SESSION['logged_at'] ?? 0) > $max) {
        auth_logout();
        return false;
    }
    return true;
}

function auth_require(): void {
    if (!auth_check()) redirect('index.php');
}

function csrf_token(): string {
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    $token = $_POST['_csrf'] ?? '';
    auth_start();
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('CSRF token 校验失败，请返回重试');
    }
}