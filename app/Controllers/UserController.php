<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class UserController
 * Scaffolding and endpoint contracts for user and staff management.
 */
class UserController extends Controller
{
    /**
     * GET /api/users
     */
    public function index(Request $request): Response
    {
        return $this->success(
            ['users' => []],
            'Users list endpoint initialized'
        );
    }

    /**
     * GET /api/users/{id}
     */
    public function show(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        return $this->success(
            ['user' => null, 'requested_id' => $id],
            'User detail endpoint initialized'
        );
    }
}
