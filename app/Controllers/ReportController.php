<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Class ReportController
 * Scaffolding and endpoint contracts for daily and monthly attendance analytics.
 */
class ReportController extends Controller
{
    /**
     * GET /api/reports/daily
     */
    public function daily(Request $request): Response
    {
        return $this->success(
            ['summary' => ['present' => 0, 'absent' => 0, 'late' => 0], 'records' => []],
            'Daily attendance report endpoint initialized'
        );
    }

    /**
     * GET /api/reports/monthly
     */
    public function monthly(Request $request): Response
    {
        return $this->success(
            ['monthly_total_hours' => 0, 'attendance_rate' => 0],
            'Monthly attendance report endpoint initialized'
        );
    }
}
