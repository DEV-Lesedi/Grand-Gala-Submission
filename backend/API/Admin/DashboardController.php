<?php

declare(strict_types=1);

namespace GrandGala\API\Admin;

use GrandGala\Services\StaffDashboard;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class DashboardController
{
    // GET /api/staff/today
    public function today(Request $request): void
    {
        Response::json(StaffDashboard::today());
    }

    // GET /api/staff/nav-counts
    public function navCounts(Request $request): void
    {
        Response::json(StaffDashboard::navCounts());
    }

    // GET /api/staff/calendar?from=2026-09-01&to=2026-09-30
    public function calendar(Request $request): void
    {
        Response::json(StaffDashboard::calendar($request->allQuery()));
    }
}
