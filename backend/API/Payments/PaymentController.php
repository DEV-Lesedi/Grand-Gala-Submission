<?php

declare(strict_types=1);

namespace GrandGala\API\Payments;

use GrandGala\Services\PaymentGateway\Payments;
use GrandGala\Utils\ClientIp;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class PaymentController
{
    // POST /api/payments/checkout { type: stay|quotation|ticket_order, reference }
    // Own booking if signed in; a guest ticket order sends X-Ticket-Access.
    public function checkout(Request $request): void
    {
        $kind = $request->inputText('type');
        $reference = trim($request->inputText('reference'));
        if ($kind === '' || $reference === '') {
            throw new HttpError(422, 'Payment type and reference are required');
        }

        $userId = null;
        $user = Request::user();
        if ($user !== null) {
            $userId = $user['id'];
        }

        $token = $_SERVER['HTTP_X_TICKET_ACCESS'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = null;
        }

        Response::json(Payments::start($kind, $reference, $userId, $token));
    }

    // POST /api/payments/payfast/notify: PayFast form post, never the guest's browser.
    public function payFastNotify(Request $request): void
    {
        Response::json(Payments::processPayFastItn($request->all(), ClientIp::remote()));
    }

    // POST /api/payments/payfast/cancel
    public function payFastCancel(Request $request): void
    {
        Response::json(Payments::cancelPayFastItn($request->all(), ClientIp::remote()));
    }

    // POST /api/staff/payments/:id/refunds { amount, reason }: queue a PayFast refund.
    public function requestRefund(Request $request): void
    {
        $paymentId = self::idParam($request);
        $amount = $request->input('amount');
        $reason = $request->input('reason');
        if (!is_numeric($amount) || !is_string($reason)) {
            throw new HttpError(422, 'Refund amount and reason are required');
        }

        $refundId = Payments::refund($paymentId, (float) $amount, $reason, Request::user()['id']);
        Response::json(['refundId' => $refundId, 'status' => 'pending'], 201);
    }

    // PATCH /api/staff/refunds/:id { gatewayReference }: refund already made in PayFast.
    public function markRefundProcessed(Request $request): void
    {
        $refundId = self::idParam($request);
        $gatewayReference = trim($request->inputText('gatewayReference'));

        Payments::markRefundProcessed($refundId, Request::user()['id'], $gatewayReference);
        Response::json(['refundId' => $refundId, 'status' => 'processed']);
    }

    private static function idParam(Request $request): int
    {
        $id = (string) $request->param('id');
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new HttpError(404, 'Not found');
        }

        return (int) $id;
    }
}
