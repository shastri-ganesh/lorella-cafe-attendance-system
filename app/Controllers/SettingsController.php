<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class SettingsController
 * Scaffolding and endpoint contracts for system and café level configurations.
 */
class SettingsController extends Controller
{
    /**
     * GET /api/settings
     */
    public function index(Request $request): Response
    {
        return $this->success(
            ['settings' => []],
            'System settings endpoint initialized'
        );
    }
}
