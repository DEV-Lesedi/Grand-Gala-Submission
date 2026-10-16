<?php

declare(strict_types=1);

namespace GrandGala\API\Payments;

use GrandGala\Services\PaymentGateway\LocalPayFast;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Local only: finish a test payment as if PayFast confirmed it. Routed when APP_ENV=local.
class LocalPaymentController
{
    // POST /api/dev/payfast/complete { merchantReference }
    public function complete(Request $request): void
    {
        $merchantReference = trim($request->inputText('merchantReference'));
        if ($merchantReference === '') {
            throw new HttpError(422, 'merchantReference is required');
        }

        Response::json(LocalPayFast::complete($merchantReference));
    }
}
