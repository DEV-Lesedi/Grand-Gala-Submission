<?php

declare(strict_types=1);

namespace GrandGala\API\Enquiries;

use GrandGala\Services\Enquiries;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class StaffEnquiriesController
{
    // GET /api/staff/enquiries?status=enquiry|quoted|closed&page= (both optional)
    public function list(Request $request): void
    {
        Response::json(Enquiries::list($request->allQuery()));
    }

    // GET /api/staff/enquiries/:reference
    public function detail(Request $request): void
    {
        Response::json(Enquiries::detail((string) $request->param('reference')));
    }
}
