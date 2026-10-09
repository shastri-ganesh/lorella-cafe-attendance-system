<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class AuditRepository
 * Data access layer for audit log trails.
 */
class AuditRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Fetch paginated audit trail.
     */
    public function getLogs(int $limit = 50, int $offset = 0, ?int $cafeId = null): array
    {
        $query = "
            SELECT l.*, u.full_name AS actor_name, c.name AS cafe_name
            FROM audit_logs l
            LEFT JOIN users u ON l.user_id = u.id
            LEFT JOIN cafes c ON l.cafe_id = c.id
        ";

        if ($cafeId !== null) {
            $query .= " WHERE l.cafe_id = :cafe_id";
        }

        $query .= " ORDER BY l.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        if ($cafeId !== null) {
            $stmt->bindValue(':cafe_id', $cafeId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
