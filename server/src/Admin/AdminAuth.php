<?php

declare(strict_types=1);

namespace Matsvep\Admin;

use PDO;
use RuntimeException;

final class AdminAuth
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('matsvep_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function login(string $email, string $password): bool
    {
        $query = $this->db->prepare("SELECT id, password_hash, failed_attempts, locked_until FROM `user` "
            . "WHERE email = ? AND role = 'admin'");
        $query->execute([$email]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            password_verify($password, '$2y$10$R3w0wq27VzUoW4QSh0w4xOww3kyLJvQDd6XxDwfToYJ1pG5C.sNdy');
            return false;
        }
        if (!is_numeric($user['id'] ?? null) || !is_numeric($user['failed_attempts'] ?? null)
            || !is_string($user['password_hash'] ?? null)) {
            throw new RuntimeException('Invalid stored admin account.');
        }
        if (is_string($user['locked_until']) && strtotime($user['locked_until'] . ' UTC') > time()) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            $maximum = $this->config('admin.login_max_attempts');
            $lockSeconds = $this->config('admin.login_lock_s');
            $failed = (int) $user['failed_attempts'] + 1;
            $update = $this->db->prepare('UPDATE `user` SET failed_attempts = ?, locked_until = ' .
                'CASE WHEN ? >= ? THEN DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) ELSE NULL END WHERE id = ?');
            $update->execute([$failed, $failed, $maximum, $lockSeconds, $user['id']]);
            $this->audit((int) $user['id'], 'auth.login_failed');
            return false;
        }
        $reset = $this->db->prepare('UPDATE `user` SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
        $reset->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['last_active'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->audit((int) $user['id'], 'auth.login');
        return true;
    }

    public function userId(): ?int
    {
        $id = $_SESSION['admin_id'] ?? null;
        $last = $_SESSION['last_active'] ?? null;
        if (!is_int($id) || !is_int($last) || time() - $last > $this->config('admin.session_idle_s')) {
            $this->logout();
            return null;
        }
        $query = $this->db->prepare("SELECT 1 FROM `user` WHERE id = ? AND role = 'admin'");
        $query->execute([$id]);
        if ($query->fetchColumn() === false) {
            $this->logout();
            return null;
        }
        $_SESSION['last_active'] = time();
        return $id;
    }

    public function csrfToken(): string
    {
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function requireCsrf(string $token): void
    {
        if (!hash_equals($this->csrfToken(), $token)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }

    public function logout(): void
    {
        $id = $_SESSION['admin_id'] ?? null;
        if (is_int($id)) {
            $this->audit($id, 'auth.logout');
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function audit(int $userId, string $action): void
    {
        $query = $this->db->prepare('INSERT INTO admin_audit (user_id, action, target_type, target_id) ' .
            'VALUES (?, ?, ?, ?)');
        $query->execute([$userId, $action, 'user', $userId]);
    }

    private function config(string $key): int
    {
        $query = $this->db->prepare('SELECT value FROM engine_config WHERE `key` = ?');
        $query->execute([$key]);
        $value = $query->fetchColumn();
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('Invalid admin configuration.');
        }
        return (int) $value;
    }
}
