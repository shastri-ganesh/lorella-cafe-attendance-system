<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\CsrfMiddleware;

/**
 * Class AuthController
 * Scaffolding and endpoint contracts for authentication, 2FA, and session management.
 */
class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     */
    public function login(Request $request): Response
    {
        $validationError = $this->validateRequired($request, ['email', 'password']);
        if ($validationError) {
            return $validationError;
        }

        // Architectural stub for Phase 2: Authentication implementation
        return $this->success(
            ['status' => 'auth_endpoint_ready'],
            'Authentication endpoint initialized (Phase 2 target)'
        );
    }

    /**
     * POST /api/auth/2fa/verify
     */
    public function verifyTwoFactor(Request $request): Response
    {
        $validationError = $this->validateRequired($request, ['code']);
        if ($validationError) {
            return $validationError;
        }

        return $this->success(
            ['status' => '2fa_endpoint_ready'],
            '2FA verification endpoint initialized (Phase 2 target)'
        );
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): Response
    {
        return $this->success([], 'Logged out successfully');
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): Response
    {
        return $this->success(
            ['authenticated' => !empty($_SESSION['user_id'])],
            'Current session status'
        );
    }

    /**
     * GET /api/auth/csrf
     * Returns fresh CSRF token for single-page and PWA clients.
     */
    public function csrfToken(Request $request): Response
    {
        $token = CsrfMiddleware::getToken();
        return $this->success(['csrf_token' => $token], 'CSRF token issued');
    }
}
