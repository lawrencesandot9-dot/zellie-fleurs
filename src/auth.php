<?php
declare(strict_types=1);

/**
 * Administrator authentication and login throttling.
 */

const ZF_LOGIN_WINDOW_MINUTES = 15;
const ZF_LOGIN_MAX_ATTEMPTS = 5;

/**
 * Admin session lifetime policy.
 *
 * Production defaults: 30 minutes of inactivity and an 8-hour absolute cap.
 * The ZF_ADMIN_IDLE_TIMEOUT and ZF_ADMIN_ABSOLUTE_TIMEOUT environment
 * variables override them at runtime — this exists purely so the HTTP test
 * harness can age sessions quickly on an isolated server; deployments that
 * do not set these variables keep the constants above unchanged.
 */
const ZF_ADMIN_IDLE_TIMEOUT = 1800;
const ZF_ADMIN_ABSOLUTE_TIMEOUT = 28800;

function zf_admin_timeouts(): array
{
    return [
        'idle' => (int) (getenv('ZF_ADMIN_IDLE_TIMEOUT') ?: ZF_ADMIN_IDLE_TIMEOUT),
        'absolute' => (int) (getenv('ZF_ADMIN_ABSOLUTE_TIMEOUT') ?: ZF_ADMIN_ABSOLUTE_TIMEOUT),
    ];
}

/**
 * Pure expiry verdict for an admin session.
 *
 * Idle timeout is measured from the last observed activity
 * (admin_last_seen, falling back to the login time); the absolute timeout
 * is measured from the login time itself. A session that is exactly at a
 * limit is still valid; one tick beyond it is not. Missing or non-positive
 * timestamps are treated as expired so a malformed session can never stay
 * authenticated.
 */
function zf_admin_session_expired(array $session, int $now, int $idle, int $absolute): bool
{
    $loginAt = (int) ($session['admin_login_at'] ?? 0);
    $lastSeen = (int) ($session['admin_last_seen'] ?? $loginAt);

    if ($loginAt <= 0 || $lastSeen <= 0) {
        return true;
    }

    if ($now - $lastSeen > $idle) {
        return true;
    }

    return $now - $loginAt > $absolute;
}

/**
 * Remove ONLY the admin keys from the session. Storefront and guest state
 * (cart, checkout tokens, flashes, CSRF) lives in the same shared session
 * and must survive an admin expiry untouched.
 */
function zf_clear_admin_session_keys(): void
{
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_login_at'],
        $_SESSION['admin_last_seen']
    );
}

function zf_expire_admin_session(): void
{
    zf_clear_admin_session_keys();

    if (PHP_SAPI === 'cli') {
        return; // CLI tests inspect the cleared state directly.
    }

    zf_redirect('login.php?expired=1');
}

function zf_admin_logged_in(): bool
{
    zf_session_start();

    if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_username'])) {
        return false;
    }

    $timeouts = zf_admin_timeouts();
    if (zf_admin_session_expired($_SESSION, time(), $timeouts['idle'], $timeouts['absolute'])) {
        zf_expire_admin_session();
        return false;
    }

    // Cross-session invalidation: changing the password stamps
    // admin_users.session_invalid_before, so every admin session that logged
    // in before that moment is dead. A missing admin row expires too.
    global $db;
    if (isset($db) && $db instanceof PDO) {
        try {
            $stmt = $db->prepare('SELECT session_invalid_before FROM admin_users WHERE id = ?');
            $stmt->execute([(int) $_SESSION['admin_id']]);
            $invalidBefore = $stmt->fetchColumn();

            if ($invalidBefore === false
                || (int) $_SESSION['admin_login_at'] < (int) $invalidBefore) {
                zf_expire_admin_session();
                return false;
            }
        } catch (Throwable $e) {
            zf_error_log('admin-session-validation-skipped', $e->getMessage(), $e->getFile() . ':' . $e->getLine());
        }
    }

    $_SESSION['admin_last_seen'] = time();

    return true;
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
        zf_error_log('login-failure', $e->getMessage(), $e->getFile() . ':' . $e->getLine());
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

    // The hash update and the session-invalidation stamp must land together:
    // once the password changes, every admin session that logged in before
    // now (including the changer's own) has to be dead.
    $db->beginTransaction();
    try {
        $update->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

        $invalidate = $db->prepare('UPDATE admin_users SET session_invalid_before = ? WHERE id = ?');
        $invalidate->execute([time(), $adminId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return ['ok' => true, 'error' => ''];
}
