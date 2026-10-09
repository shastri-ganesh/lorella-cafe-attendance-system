<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Class CafeRepository
 * Data access layer for cafés/branches and physical geofence boundaries.
 */
class CafeRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Find café by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM cafes WHERE id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * List all active cafés.
     */
    public function getAllActive(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, code, address, latitude, longitude, radius_meters, timezone
            FROM cafes
            WHERE status = 'active'
            ORDER BY name ASC
        ");
        return $stmt->fetchAll();
    }
}
