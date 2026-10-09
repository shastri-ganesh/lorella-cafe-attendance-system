<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class AttendanceRepository
 * Data access layer for attendance check-ins, check-outs, and history logs.
 */
class AttendanceRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Get active checked-in record for an employee for a specific date.
     */
    public function getActiveCheckIn(int $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM attendance 
            WHERE user_id = :user_id 
              AND attendance_date = :att_date 
              AND status = 'checked_in'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':att_date' => $date,
        ]);
        $record = $stmt->fetch();
        return $record ?: null;
    }

    /**
     * Get user's attendance history with pagination.
     */
    public function getUserHistory(int $userId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, c.name AS cafe_name
            FROM attendance a
            JOIN cafes c ON a.cafe_id = c.id
            WHERE a.user_id = :user_id
            ORDER BY a.attendance_date DESC, a.check_in_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
