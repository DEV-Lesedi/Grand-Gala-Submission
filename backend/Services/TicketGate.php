<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PublicEvent;
use GrandGala\Models\Space;
use GrandGala\Models\Ticket;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * The gate. A ticket can be scanned on the event day, and only once.
 * The public page a QR code opens shows validity, a short name and the event.
 */
class TicketGate
{
    // Gate scan: event day only, a valid ticket of a paid order, and only once.
    // Anyone with the code may see it, so only validity, a short name and the event are returned.
    public static function confirmation(string $code): array
    {
        $ticket = Ticket::forConfirmation(self::codeFrom($code));
        if ($ticket === null) {
            throw new HttpError(404, 'Ticket not found');
        }

        $holder = $ticket['attendeeName'];
        if ($holder === null || trim($holder) === '') {
            $holder = trim($ticket['buyerName'] . ' ' . mb_substr((string) $ticket['buyerSurname'], 0, 1) . '.');
        }

        return [
            'status' => $ticket['status'],
            'holder' => $holder,
            'tier' => $ticket['tier'],
            'event' => [
                'title' => $ticket['eventTitle'],
                'date' => $ticket['eventDate'],
                'startTime' => $ticket['eventStartTime'],
            ],
            'usedAt' => Events::apiDateTime($ticket['usedAt']),
        ];
    }

    // The code itself, or the confirmation link the QR code holds.
    private static function codeFrom(string $scanned): string
    {
        $scanned = trim($scanned);
        $position = strrpos($scanned, '/ticket/');
        if ($position !== false) {
            $scanned = substr($scanned, $position + strlen('/ticket/'));
        }

        return $scanned;
    }

    public static function scan(int $eventId, array $input): array
    {
        $code = $input['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            throw new HttpError(422, 'Send the ticket code');
        }
        // The QR code holds a link (…/ticket/<code>); a scanner may send the whole link
        $code = self::codeFrom($code);
        $event = self::findEvent($eventId);

        return Db::transaction(function () use ($event, $code) {
            // Lock order: venue, event, then the ticket, so two gates wait on each other.
            Space::lockVenue($event['venueId']);
            $event = PublicEvent::find($event['id'], true);
            if ($event['status'] !== 'confirmed') {
                throw new HttpError(409, 'This event is not open for entry');
            }
            if ($event['date'] !== Clock::today()) {
                throw new HttpError(422, 'Tickets can only be scanned on the event day');
            }

            $ticket = Ticket::findByCode(trim($code), true);
            if ($ticket === null || $ticket['eventId'] !== $event['id']) {
                throw new HttpError(404, 'Ticket not found for this event');
            }
            if ($ticket['status'] === 'used') {
                throw new HttpError(409, 'This ticket has already been used');
            }
            if ($ticket['orderStatus'] !== 'paid' || $ticket['status'] !== 'valid') {
                throw new HttpError(409, 'This ticket is no longer valid');
            }

            // Updates only a still-valid ticket, so it cannot be used twice.
            $now = Clock::now();
            if (!Ticket::markUsed($ticket['id'], $now)) {
                throw new HttpError(409, 'This ticket has already been used');
            }

            return ['code' => $ticket['code'], 'status' => 'used', 'tier' => $ticket['tier'], 'usedAt' => Events::apiDateTime($now)];
        });
    }

    // A public event for staff lists and the gate, or 404
    private static function findEvent(int $eventId): array
    {
        $event = PublicEvent::find($eventId);
        if ($event === null) {
            throw new HttpError(404, 'Event not found');
        }

        return $event;
    }

}
