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
     * Authenticates user credentials and establishes initial session state.
     */
    public function login(Request $request): Response
    {
        $login = (string) ($request->input('login') ?? $request->input('email') ?? '');
        $password = (string) ($request->input('password') ?? '');

        if (trim($login) === '' || trim($password) === '') {
            return $this->error('Email and password are required.', [
                'login' => 'Email or login identifier is required.',
                'password' => 'Password is required.',
            ], 400);
        }

        $result = \App\Services\AuthService::authenticate($login, $password, $request);

        if (!$result['success']) {
            return $this->error(
                $result['message'],
                $result['errors'] ?? [],
                $result['status'] ?? 401
            );
        }

        return $this->success(
            $result['data'],
            $result['message'],
            $result['status'] ?? 200
        );
    }

    /**
     * POST /api/auth/2fa/setup
     * Generates unconfirmed Base32 secret, otpauth:// URI, and local SVG QR code.
     */
    public function setupTwoFactor(Request $request): Response
    {
        $result = \App\Services\AuthService::setupTwoFactor($request);

        if (!$result['success']) {
            return $this->error(
                $result['message'],
                [],
                $result['status'] ?? 400
            );
        }

        return $this->success(
            $result['data'],
            $result['message'],
            $result['status'] ?? 200
        );
    }

    /**
     * POST /api/auth/2fa/verify
     * Verifies TOTP code or single-use recovery code.
     * Completes 2FA enrollment or upgrades 2fa_pending session to authenticated.
     */
    public function verifyTwoFactor(Request $request): Response
    {
        $code = $request->input('code');
        $recoveryCode = $request->input('recovery_code');

        if (($code === null || trim((string) $code) === '') && ($recoveryCode === null || trim((string) $recoveryCode) === '')) {
            return $this->error('A 6-digit verification code or recovery code is required.', [
                'code' => 'Verification code or recovery code is required.',
            ], 400);
        }

        $result = \App\Services\AuthService::verifyTwoFactor(
            $code !== null ? (string) $code : null,
            $recoveryCode !== null ? (string) $recoveryCode : null,
            $request
        );

        if (!$result['success']) {
            return $this->error(
                $result['message'],
                [],
                $result['status'] ?? 401
            );
        }

        return $this->success(
            $result['data'],
            $result['message'],
            $result['status'] ?? 200
        );
    }

    /**
     * GET /api/auth/2fa/status
     * Returns safe status of two-factor authentication for current session user.
     */
    public function twoFactorStatus(Request $request): Response
    {
        $result = \App\Services\AuthService::getTwoFactorStatus();

        if (!$result['success']) {
            return $this->error(
                $result['message'],
                [],
                $result['status'] ?? 401
            );
        }

        return $this->success(
            $result['data'],
            $result['message'],
            $result['status'] ?? 200
        );
    }

    /**
     * POST /api/auth/logout
     * Securely destroys active or 2FA pending session and revokes cookie.
     */
    public function logout(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            \App\Services\AuthService::startSecureSession();
        }

        $hasSession = !empty($_SESSION['user_id']) || !empty($_SESSION['pending_user_id']) || !empty($_SESSION['auth_state']);

        if (!$hasSession) {
            return $this->error('Unauthenticated.', [], 401);
        }

        \App\Services\AuthService::logout($request);

        return $this->success([], 'Logged out successfully');
    }

    /**
     * GET /api/auth/me
     * Returns sanitized profile of authenticated user or 2FA pending status.
     */
    public function me(Request $request): Response
    {
        $userProfile = \App\Services\AuthService::getAuthenticatedUser();

        if ($userProfile === null) {
            return $this->error('Unauthenticated. Please log in.', [], 401);
        }

        if (($userProfile['auth_state'] ?? '') === '2fa_pending') {
            return $this->success($userProfile, 'Two-factor authentication verification required.');
        }

        return $this->success($userProfile, 'Current authenticated user profile');
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
