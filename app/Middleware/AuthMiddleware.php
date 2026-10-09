<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Class AuthMiddleware
 * Enforces session authentication and verifies account status and 2FA state.
 */
class AuthMiddleware
{
    /**
     * Handle incoming request authorization.
     * Enforces three distinct lifecycle states:
     * 1. UNAUTHENTICATED -> HTTP 401
     * 2. 2FA_PENDING      -> Allowed only for /api/auth/me, /api/auth/logout, and /api/auth/2fa/verify; else HTTP 403
     * 3. FULLY_AUTHENTICATED -> Allowed
     */
    public function handle(Request $request): ?Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            \App\Services\AuthService::startSecureSession();
        }

        $authState = $_SESSION['auth_state'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;
        $pendingUserId = $_SESSION['pending_user_id'] ?? null;

        // State 1: Completely unauthenticated
        if (!$authState || (!$userId && !$pendingUserId)) {
            if ($request->expectsJson()) {
                return Response::jsonError('Unauthenticated. Please log in.', [], 401);
            }
            header('Location: /login.php');
            exit;
        }

        // State 2: Authenticated with password, but TOTP 2FA is pending
        if ($authState === '2fa_pending' || !empty($_SESSION['2fa_pending'])) {
            $path = $request->getPath();
            $allowedPendingPaths = [
                '/api/auth/me',
                '/api/auth/logout',
                '/api/auth/2fa/verify',
                '/api/auth/2fa/setup',
                '/api/auth/2fa/status',
                '/2fa.php',
            ];

            if (in_array($path, $allowedPendingPaths, true)) {
                return null; // Permit 2FA-related endpoints to proceed
            }

            if ($request->expectsJson()) {
                return Response::jsonError(
                    'Two-factor authentication verification required.',
                    ['auth_state' => '2fa_pending', 'requires_2fa' => true],
                    403
                );
            }

            header('Location: /2fa.php');
            exit;
        }

        // State 3: Fully authenticated
        if ($authState === 'authenticated' && $userId) {
            return null;
        }

        // Catch-all invalid session state
        \App\Services\AuthService::logout($request);
        return Response::jsonError('Invalid session state. Please log in again.', [], 401);
    }
}
