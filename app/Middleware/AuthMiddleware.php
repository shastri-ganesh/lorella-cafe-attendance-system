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
     */
    public function handle(Request $request): ?Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Verify authenticated session existence
        if (empty($_SESSION['user_id'])) {
            if ($request->expectsJson()) {
                return Response::jsonError('Unauthenticated. Please log in.', [], 401);
            }
            // For browser requests, redirect to login
            header('Location: /login.php');
            exit;
        }

        // If 2FA is pending completion, restrict access to 2FA verification endpoints only
        if (!empty($_SESSION['2fa_pending'])) {
            $path = $request->getPath();
            if ($path !== '/api/auth/2fa/verify' && $path !== '/2fa.php') {
                if ($request->expectsJson()) {
                    return Response::jsonError('Two-factor authentication verification required.', [], 403);
                }
                header('Location: /2fa.php');
                exit;
            }
        }

        return null;
    }
}
