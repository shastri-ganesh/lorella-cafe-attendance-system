<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class Controller
 * Base controller providing helper methods for standard JSON responses and parameter validation.
 */
abstract class Controller
{
    /**
     * Send standardized success JSON response.
     */
    protected function success(mixed $data = [], string $message = 'Success', int $statusCode = 200): Response
    {
        return Response::jsonSuccess($data, $message, $statusCode);
    }

    /**
     * Send standardized error JSON response.
     */
    protected function error(string $message = 'Error', array $errors = [], int $statusCode = 400): Response
    {
        return Response::jsonError($message, $errors, $statusCode);
    }

    /**
     * Validate presence of required input keys in request.
     */
    protected function validateRequired(Request $request, array $requiredFields): ?Response
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            $val = $request->input($field);
            if ($val === null || (is_string($val) && trim($val) === '')) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return $this->error('Missing required fields.', ['missing' => $missing], 422);
        }

        return null;
    }

    /**
     * Get authenticated user ID from active session.
     */
    protected function getAuthUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}
