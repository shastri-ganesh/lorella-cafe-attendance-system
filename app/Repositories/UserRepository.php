<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class UserRepository
 * Data access layer for users, roles, and two-factor authentication credentials.
 */
class UserRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Find user by ID with role slug.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.slug AS role_slug, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Find user by email.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, r.slug AS role_slug, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Fetch user's assigned café IDs.
     */
    public function getAssignedCafeIds(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT cafe_id FROM user_cafes WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Fetch 2FA configuration for user.
     */
    public function getTwoFactorRecord(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM two_factor_auth WHERE user_id = :user_id LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Increment failed login attempts and lock account if threshold reached.
     */
    public function incrementFailedAttempts(int $userId, int $lockoutMinutes = 15, int $threshold = 5): void
    {
        $lockoutUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));

        $stmt = $this->db->prepare("
            UPDATE users 
            SET failed_attempts = failed_attempts + 1,
                locked_until = CASE WHEN (failed_attempts + 1) >= :threshold THEN :lockout_until ELSE locked_until END
            WHERE id = :id
        ");
        $stmt->bindValue(':threshold', (int) $threshold, \PDO::PARAM_INT);
        $stmt->bindValue(':lockout_until', $lockoutUntil, \PDO::PARAM_STR);
        $stmt->bindValue(':id', (int) $userId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Reset failed attempts after successful authentication.
     */
    public function resetFailedAttempts(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id
        ");
        $stmt->execute([':id' => $userId]);
    }

    /**
     * Check if account is currently locked.
     */
    public function isAccountLocked(array $user): bool
    {
        if (empty($user['locked_until'])) {
            return false;
        }
        return strtotime($user['locked_until']) > time();
    }

    /**
     * Update password hash when rehashing is required.
     */
    public function updatePasswordHash(int $userId, string $newHash): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET password_hash = :hash WHERE id = :id
        ");
        $stmt->execute([':hash' => $newHash, ':id' => $userId]);
    }

    /**
     * Update user last login timestamp.
     */
    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET last_login_at = :login_time WHERE id = :id
        ");
        $stmt->execute([
            ':login_time' => date('Y-m-d H:i:s'),
            ':id' => $userId,
        ]);
    }

    /**
     * Fetch user's assigned café details (safe fields only).
     */
    public function getAssignedCafes(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.code, c.address, c.timezone, uc.is_primary
            FROM user_cafes uc
            JOIN cafes c ON uc.cafe_id = c.id
            WHERE uc.user_id = :user_id AND c.status = 'active'
            ORDER BY uc.is_primary DESC, c.name ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
