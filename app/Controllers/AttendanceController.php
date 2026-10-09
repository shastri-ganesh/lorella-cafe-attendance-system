<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class AttendanceController
 * Scaffolding and endpoint contracts for server-authoritative attendance operations.
 */
class AttendanceController extends Controller
{
    /**
     * POST /api/attendance/check-in
     */
    public function checkIn(Request $request): Response
    {
        $validationError = $this->validateRequired($request, ['latitude', 'longitude', 'accuracy']);
        if ($validationError) {
            return $validationError;
        }

        return $this->success(
            ['status' => 'checkin_endpoint_ready'],
            'Check-in endpoint initialized (Phase 3 target)'
        );
    }

    /**
     * POST /api/attendance/check-out
     */
    public function checkOut(Request $request): Response
    {
        $validationError = $this->validateRequired($request, ['latitude', 'longitude', 'accuracy']);
        if ($validationError) {
            return $validationError;
        }

        return $this->success(
            ['status' => 'checkout_endpoint_ready'],
            'Check-out endpoint initialized (Phase 3 target)'
        );
    }

    /**
     * GET /api/attendance/status
     */
    public function status(Request $request): Response
    {
        return $this->success(
            ['status' => 'checked_out', 'attendance' => null],
            'Current attendance status endpoint initialized'
        );
    }

    /**
     * GET /api/attendance/history
     */
    public function history(Request $request): Response
    {
        return $this->success(
            ['records' => [], 'total' => 0],
            'Attendance history endpoint initialized'
        );
    }
}
