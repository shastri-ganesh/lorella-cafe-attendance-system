<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CafeRepository;

/**
 * Class CafeController
 * Scaffolding and endpoint contracts for café/branch management.
 */
class CafeController extends Controller
{
    private CafeRepository $cafeRepo;

    public function __construct()
    {
        $this->cafeRepo = new CafeRepository();
    }

    /**
     * GET /api/cafes
     */
    public function index(Request $request): Response
    {
        return $this->success(
            ['cafes' => []],
            'Cafés list endpoint initialized'
        );
    }

    /**
     * GET /api/cafes/{id}
     */
    public function show(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        return $this->success(
            ['cafe' => null, 'requested_id' => $id],
            'Café detail endpoint initialized'
        );
    }
}
