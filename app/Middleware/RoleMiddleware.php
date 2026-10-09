<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;

/**
 * Class RoleMiddleware
 * Centralized RBAC guard verifying user roles, café multi-tenancy boundaries,
 * and role escalation prevention rules.
 */
class RoleMiddleware
{
    /**
     * Role hierarchy weights for escalation and boundary validation.
     */
    private const ROLE_HIERARCHY = [
        'super_admin' => 4,
        'admin'       => 3,
        'manager'     => 2,
        'staff'       => 1,
    ];

    /**
     * Check if authenticated user possesses one of the allowed roles.
     */
    public static function requireRole(array|string $roles): callable
    {
        $allowedRoles = (array) $roles;

        return function (Request $request) use ($allowedRoles): ?Response {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                \App\Services\AuthService::startSecureSession();
            }

            $userId = $_SESSION['user_id'] ?? null;
            $userRole = $_SESSION['user_role'] ?? null;

            if (!$userId || !$userRole) {
                return Response::jsonError('Unauthenticated. Please log in.', [], 401);
            }

            if (!in_array($userRole, $allowedRoles, true)) {
                AuditService::log(
                    'UNAUTHORIZED_ROLE_ACCESS_ATTEMPT',
                    'users',
                    (int) $userId,
                    (int) $userId,
                    null,
                    [],
                    ['current_role' => $userRole, 'required_roles' => $allowedRoles, 'path' => $request->getPath()],
                    $request
                );
                return Response::jsonError('Forbidden: Insufficient privileges for this resource.', [], 403);
            }

            return null;
        };
    }

    /**
     * Route middleware verifying access to a specific café.
     * Super Admin has global access; Admin/Manager/Staff must be explicitly assigned.
     */
    public static function requireCafeAccess(string $paramName = 'id'): callable
    {
        return function (Request $request, array $params = []) use ($paramName): ?Response {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                \App\Services\AuthService::startSecureSession();
            }

            $userId = $_SESSION['user_id'] ?? null;
            $userRole = $_SESSION['user_role'] ?? null;

            if (!$userId || !$userRole) {
                return Response::jsonError('Unauthenticated. Please log in.', [], 401);
            }

            $rawCafeId = $params[$paramName] ?? $request->param($paramName) ?? $request->get($paramName) ?? $request->input($paramName) ?? null;

            if ($rawCafeId === null || !is_numeric($rawCafeId)) {
                return Response::jsonError('Café identifier is required.', [], 400);
            }

            $cafeId = (int) $rawCafeId;

            if (!self::canAccessCafe($cafeId)) {
                AuditService::log(
                    'UNAUTHORIZED_CAFE_ACCESS_ATTEMPT',
                    'cafes',
                    $cafeId,
                    (int) $userId,
                    null,
                    [],
                    ['requested_cafe_id' => $cafeId, 'role' => $userRole],
                    $request
                );
                return Response::jsonError('Forbidden: Access denied to the requested café.', [], 403);
            }

            return null;
        };
    }

    /**
     * Programmatic check whether the authenticated user has authorization for a specific café.
     */
    public static function canAccessCafe(int $cafeId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            \App\Services\AuthService::startSecureSession();
        }

        $userRole = $_SESSION['user_role'] ?? null;
        if ($userRole === 'super_admin') {
            return true;
        }

        $assignedCafes = array_map('intval', (array) ($_SESSION['user_cafe_ids'] ?? []));
        return in_array($cafeId, $assignedCafes, true);
    }

    /**
     * Guard verifying that a user can only access their own user resource,
     * unless they hold one of the permitted privileged roles.
     */
    public static function requireSelfOrRole(array|string $privilegedRoles = ['super_admin', 'admin', 'manager'], string $paramName = 'id'): callable
    {
        $allowedPrivileged = (array) $privilegedRoles;

        return function (Request $request, array $params = []) use ($allowedPrivileged, $paramName): ?Response {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                \App\Services\AuthService::startSecureSession();
            }

            $currentUserId = $_SESSION['user_id'] ?? null;
            $currentUserRole = $_SESSION['user_role'] ?? null;

            if (!$currentUserId || !$currentUserRole) {
                return Response::jsonError('Unauthenticated. Please log in.', [], 401);
            }

            $rawTargetUserId = $params[$paramName] ?? $request->param($paramName) ?? $request->get($paramName) ?? $request->input($paramName) ?? null;

            if ($rawTargetUserId === null || !is_numeric($rawTargetUserId)) {
                return Response::jsonError('User identifier is required.', [], 400);
            }

            $targetUserId = (int) $rawTargetUserId;

            // Self-access is always permitted
            if ((int) $currentUserId === $targetUserId) {
                return null;
            }

            // Otherwise, user must hold an authorized privileged role
            if (in_array($currentUserRole, $allowedPrivileged, true)) {
                return null;
            }

            AuditService::log(
                'UNAUTHORIZED_USER_ACCESS_ATTEMPT',
                'users',
                $targetUserId,
                (int) $currentUserId,
                null,
                [],
                ['target_user_id' => $targetUserId, 'actor_role' => $currentUserRole],
                $request
            );

            return Response::jsonError('Forbidden: Access denied to another user\'s resource.', [], 403);
        };
    }

    /**
     * Role escalation check: Can actor role manage target role?
     * Super Admin can manage all roles.
     * Admin can manage Manager and Staff.
     * Manager can manage Staff.
     * Staff cannot manage any user.
     */
    public static function canManageRole(string $actorRole, string $targetRole): bool
    {
        $actor = strtolower(trim($actorRole));
        $target = strtolower(trim($targetRole));

        if ($actor === 'super_admin') {
            return true;
        }

        $actorWeight = self::ROLE_HIERARCHY[$actor] ?? 0;
        $targetWeight = self::ROLE_HIERARCHY[$target] ?? 0;

        return $actorWeight > $targetWeight;
    }

    /**
     * Role escalation check: Can actor role assign target role?
     * Prevents self-elevation and horizontal/vertical role elevation.
     */
    public static function canAssignRole(string $actorRole, string $newRole): bool
    {
        $actor = strtolower(trim($actorRole));
        $new = strtolower(trim($newRole));

        if ($actor === 'super_admin') {
            return true;
        }

        $actorWeight = self::ROLE_HIERARCHY[$actor] ?? 0;
        $newWeight = self::ROLE_HIERARCHY[$new] ?? 0;

        return $actorWeight > $newWeight;
    }
}

