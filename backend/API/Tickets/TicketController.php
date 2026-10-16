<?php

declare(strict_types=1);

namespace GrandGala\API\Tickets;

use GrandGala\Services\Tickets;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// Guest checkout works signed in or not.
class TicketController
{
    // POST /api/ticket-orders { eventId, name, surname, email, phone?, items, promoCode? }
    public function create(Request $request): void
    {
        Response::json(Tickets::createOrder($request->all(), self::userIdOrNull()), 201);
    }

    // GET /api/ticket-orders/:reference (guests send their receipt token in X-Ticket-Access)
    public function detail(Request $request): void
    {
        Response::json(Tickets::detail(self::reference($request), self::userIdOrNull(), self::receiptToken()));
    }

    // POST /api/ticket-orders/:reference/cancel
    public function cancel(Request $request): void
    {
        Response::json(Tickets::cancelUnpaid(self::reference($request), self::userIdOrNull(), self::receiptToken()));
    }

    // GET /api/ticket-orders/:reference/tickets/:code/qr: SVG; no-store (a QR admits entry).
    public function qr(Request $request): void
    {
        $svg = Tickets::qrSvg(self::reference($request), (string) $request->param('code'), self::userIdOrNull(), self::receiptToken());

        http_response_code(200);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $svg;
    }

    // GET /api/me/ticket-orders
    public function myOrders(Request $request): void
    {
        Response::json(['items' => Tickets::myOrders(self::signedInId())]);
    }

    // GET /api/me/tickets
    public function myTickets(Request $request): void
    {
        Response::json(['items' => Tickets::myTickets(self::signedInId())]);
    }

    // GET /api/me/tickets/:reference
    public function myOrder(Request $request): void
    {
        Response::json(Tickets::myOrder(self::reference($request), self::signedInId()));
    }

    // POST /api/staff/ticket-orders/:reference/refund { reason }
    public function refund(Request $request): void
    {
        Response::json(Tickets::refund(self::reference($request), $request->all()));
    }

    // POST /api/staff/ticket-orders/:reference/resend
    public function resend(Request $request): void
    {
        Response::json(Tickets::resend(self::reference($request)));
    }

    // GET /api/tickets/:code: public page a QR opens; nothing private in it.
    public function confirmation(Request $request): void
    {
        Response::json(Tickets::confirmation((string) $request->param('code')));
    }

    private static function reference(Request $request): string
    {
        return (string) $request->param('reference');
    }

    // Null on optional-user routes (guest checkout).
    private static function userIdOrNull(): ?int
    {
        $user = Request::user();
        if ($user === null || !isset($user['id'])) {
            return null;
        }

        return (int) $user['id'];
    }

    // 'user' middleware already requires a signed-in guest.
    private static function signedInId(): int
    {
        $id = self::userIdOrNull();
        if ($id === null) {
            throw new HttpError(401, 'Please sign in');
        }

        return $id;
    }

    // Receipt token from X-Ticket-Access (PHP: HTTP_X_TICKET_ACCESS).
    private static function receiptToken(): ?string
    {
        $token = $_SERVER['HTTP_X_TICKET_ACCESS'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }
}
