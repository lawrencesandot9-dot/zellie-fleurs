<?php
declare(strict_types=1);

/**
 * Administrator authentication and login throttling.
 */

const ZF_LOGIN_WINDOW_MINUTES = 15;
const ZF_LOGIN_MAX_ATTEMPTS = 5;

function zf_admin_logged_in(): bool
{
    zf_session_start();

    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_username']);
}

function zf_current_admin(): ?array
{
    if (!zf_admin_logged_in()) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['admin_id'],
        'username' => (string) $_SESSION['admin_username'],
    ];
}

function zf_require_admin(): void
{
    if (!zf_admin_logged_in()) {
        zf_redirect('login.php');
    }
}

function zf_auth_login(PDO $db, string $username, string $password): array
{
    $username = trim($username);
    $ip = zf_client_ip();

    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Please enter your username and password.'];
    }

    try {
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', 'localtime', '-1 day')");

        $check = $db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE attempted_at >= datetime('now', 'localtime', ?)
              AND (username = ? OR ip_address = ?)
        ");
        $check->execute(['-' . ZF_LOGIN_WINDOW_MINUTES . ' minutes', $username, $ip]);
        $attempts = (int) $check->fetchColumn();

        if ($attempts >= ZF_LOGIN_MAX_ATTEMPTS) {
            return [
                'ok' => false,
                'error' => 'Too many failed sign-in attempts. Please wait 15 minutes and try again.',
            ];
        }

        $stmt = $db->prepare('SELECT * FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, (string) $admin['password_hash'])) {
            if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
                $update = $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            }

            $clear = $db->prepare('DELETE FROM login_attempts WHERE username = ? OR ip_address = ?');
            $clear->execute([$username, $ip]);

            zf_session_start();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = (string) $admin['username'];
            $_SESSION['admin_login_at'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            return ['ok' => true, 'error' => ''];
        }

        $record = $db->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)');
        $record->execute([$username, $ip]);
    } catch (Throwable $e) {
        error_log('[Zellie Fleurs] Login error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'We could not sign you in right now. Please try again.'];
    }

    return ['ok' => false, 'error' => 'Invalid username or password.'];
}

function zf_auth_logout(): void
{
    zf_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function zf_change_admin_password(PDO $db, int $adminId, string $current, string $new, string $confirm): array
{
    if (strlen($new) < 8) {
        return ['ok' => false, 'error' => 'The new password must be at least 8 characters long.'];
    }
    if ($new !== $confirm) {
        return ['ok' => false, 'error' => 'The new password and confirmation do not match.'];
    }

    $stmt = $db->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($current, (string) $admin['password_hash'])) {
        return ['ok' => false, 'error' => 'Your current password is not correct.'];
    }

    $update = $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
    $update->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

    return ['ok' => true, 'error' => ''];
}
