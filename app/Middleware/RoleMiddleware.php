<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Class RoleMiddleware
 * Centralized RBAC guard verifying user roles and café scoping boundaries.
 */
class RoleMiddleware
{
    /**
     * Check if user possesses one of the allowed roles.
     */
    public static function requireRole(array|string $roles): callable
    {
        $allowedRoles = (array) $roles;

        return function (Request $request) use ($allowedRoles): ?Response {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $userRole = $_SESSION['user_role'] ?? null;

            if (!$userRole || !in_array($userRole, $allowedRoles, true)) {
                return Response::jsonError('Forbidden: Insufficient privileges for this resource.', [], 403);
            }

            return null;
        };
    }

    /**
     * Verify whether the authenticated user has authorization for a specific café.
     * Super Admin has global access; Admin/Manager must be assigned to the café.
     */
    public static function canAccessCafe(int $cafeId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userRole = $_SESSION['user_role'] ?? null;
        if ($userRole === 'super_admin') {
            return true;
        }

        $assignedCafes = $_SESSION['user_cafe_ids'] ?? [];
        return in_array($cafeId, (array) $assignedCafes, true);
    }
}
