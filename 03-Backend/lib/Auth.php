<?php
declare(strict_types=1);

namespace Suedsalat;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function currentAdminId(): ?int
    {
        self::startSession();
        return $_SESSION['admin_id'] ?? null;
    }

    public static function requireLogin(): int
    {
        $id = self::currentAdminId();
        if ($id === null) {
            header('Location: ' . BASE_PATH . '/admin/login.php');
            exit;
        }

        $timeoutSeconds = ADMIN_IDLE_TIMEOUT_MINUTES * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSeconds) {
            self::logout();
            header('Location: ' . BASE_PATH . '/admin/login.php?timeout=1');
            exit;
        }
        $_SESSION['last_activity'] = time();

        return $id;
    }

    public static function login(int $adminId): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    /** True, wenn die IP fuer diesen Admin (per E-Mail) aktuell gesperrt ist. */
    public static function isRateLimited(string $email, string $ipAddress): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts la
             JOIN admins a ON a.id = la.admin_id
             WHERE a.email = :email
               AND la.ip_address = :ip
               AND la.succeeded = 0
               AND la.attempted_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':ip', $ipAddress);
        $stmt->bindValue(':minutes', LOGIN_LOCKOUT_MINUTES, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    }

    public static function recordLoginAttempt(?int $adminId, string $ipAddress, bool $succeeded): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (admin_id, ip_address, succeeded) VALUES (:admin_id, :ip, :succeeded)'
        );
        $stmt->execute([
            ':admin_id' => $adminId,
            ':ip' => $ipAddress,
            ':succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
