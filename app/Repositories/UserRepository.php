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
     * Increment failed login attempts.
     */
    public function incrementFailedAttempts(int $userId, int $lockoutMinutes = 15, int $threshold = 5): void
    {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET failed_attempts = failed_attempts + 1,
                locked_until = IF(failed_attempts + 1 >= :threshold, DATE_ADD(NOW(), INTERVAL :lockout MINUTE), locked_until)
            WHERE id = :id
        ");
        $stmt->execute([
            ':threshold' => $threshold,
            ':lockout' => $lockoutMinutes,
            ':id' => $userId,
        ]);
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
}
