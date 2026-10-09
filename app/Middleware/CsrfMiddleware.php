<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Class CsrfMiddleware
 * Protects state-changing HTTP requests against Cross-Site Request Forgery.
 */
class CsrfMiddleware
{
    public const TOKEN_KEY = '_csrf_token';

    /**
     * Generate or return active CSRF token.
     */
    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Handle incoming request and validate CSRF token on mutation methods.
     */
    public function handle(Request $request): ?Response
    {
        $method = $request->getMethod();

        // Safe HTTP methods do not require CSRF token
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? null;
        if (!$sessionToken) {
            return Response::jsonError('CSRF token missing from session.', [], 419);
        }

        // Check header or request body
        $submittedToken = $request->header('X-CSRF-TOKEN') ??
                          $request->input('_csrf_token') ??
                          $request->json('_csrf_token');

        if (!$submittedToken || !hash_equals($sessionToken, (string) $submittedToken)) {
            return Response::jsonError('Invalid or expired CSRF token.', [], 419);
        }

        return null;
    }
}
