<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class LeaveController
 * Scaffolding and endpoint contracts for employee leave requests and approvals.
 */
class LeaveController extends Controller
{
    /**
     * GET /api/leave
     */
    public function index(Request $request): Response
    {
        return $this->success(
            ['requests' => []],
            'Leave requests list endpoint initialized'
        );
    }

    /**
     * POST /api/leave/request
     */
    public function create(Request $request): Response
    {
        $validationError = $this->validateRequired($request, ['leave_type_id', 'start_date', 'end_date', 'reason']);
        if ($validationError) {
            return $validationError;
        }

        return $this->success(
            ['status' => 'leave_request_submitted'],
            'Leave request submission endpoint initialized'
        );
    }
}
