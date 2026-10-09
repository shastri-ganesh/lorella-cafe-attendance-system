<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

/**
 * Class AuditService
 * Records security-sensitive, authentication, and administrative actions in audit_logs.
 */
class AuditService
{
    /**
     * Log an event into the audit_logs table.
     */
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?int $userId = null,
        ?int $cafeId = null,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null
    ): void {
        try {
            $pdo = Database::getConnection();

            $ip = $request ? $request->ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $ua = $request ? $request->userAgent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System');

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, cafe_id, action, entity_type, entity_id, 
                    old_values, new_values, ip_address, user_agent, created_at
                ) VALUES (
                    :user_id, :cafe_id, :action, :entity_type, :entity_id,
                    :old_values, :new_values, :ip_address, :user_agent, NOW()
                )
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':cafe_id' => $cafeId,
                ':action' => $action,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':old_values' => !empty($oldValues) ? json_encode($oldValues) : null,
                ':new_values' => !empty($newValues) ? json_encode($newValues) : null,
                ':ip_address' => $ip,
                ':user_agent' => substr($ua, 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Fail silently or log to error log to avoid breaking critical transactional paths
            error_log("AuditService failed to record event [{$action}]: " . $e->getMessage());
        }
    }
}
