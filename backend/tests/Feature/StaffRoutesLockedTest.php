<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

// Staff routes stay 401 until sign-in exists, so prices and blackouts cannot change by accident.
class StaffRoutesLockedTest extends FeatureTestCase
{
    // [method, path, body]
    public static function staffRoutes(): array
    {
        $season = ['basis' => 'per_night', 'label' => 'Peak', 'price' => 3500, 'validFrom' => '2026-12-01', 'validTo' => '2026-12-31'];

        return [
            'add a price' => ['POST', '/api/staff/spaces/1/rates', $season],
            'season overlaps' => ['GET', '/api/staff/spaces/1/rates/overlaps?basis=per_night&from=2026-12-01&to=2026-12-31', null],
            'cancel a price' => ['POST', '/api/staff/rates/1/cancel', null],
            'end a season now' => ['POST', '/api/staff/rates/1/end-now', null],
            'add a blackout' => ['POST', '/api/staff/blackouts', ['start' => '2026-12-24', 'end' => '2026-12-26', 'reason' => 'Repairs', 'spaceIds' => [1]]],
            'delete a blackout' => ['DELETE', '/api/staff/blackouts/1', null],
            'list spaces' => ['GET', '/api/staff/spaces', null],
            'add a space' => ['POST', '/api/staff/spaces', ['name' => 'Hall', 'venueId' => 1]],
            'edit a space' => ['PATCH', '/api/staff/spaces/1', ['isActive' => false]],
            'list amenities' => ['GET', '/api/staff/amenities', null],
            'add an amenity' => ['POST', '/api/staff/amenities', ['name' => 'Picnic basket', 'price' => 180]],
            'edit an amenity' => ['PATCH', '/api/staff/amenities/1', ['isActive' => false]],
            'rate card' => ['GET', '/api/staff/rate-card', null],
            'list bookings' => ['GET', '/api/staff/bookings?view=active', null],
            'booking detail' => ['GET', '/api/staff/bookings/GGG-2026-0417', null],
            'event accommodation list' => ['GET', '/api/staff/bookings/GGG-2026-0419/accommodation', null],
            'give a room to event guests' => ['POST', '/api/staff/bookings/GGG-2026-0419/accommodation', ['spaceId' => 1, 'checkIn' => '2026-11-21', 'checkOut' => '2026-11-22']],
            'take a room back' => ['DELETE', '/api/staff/bookings/GGG-2026-0419/accommodation/1', null],
            'today dashboard' => ['GET', '/api/staff/today', null],
            'navigation counts' => ['GET', '/api/staff/nav-counts', null],
            'staff calendar' => ['GET', '/api/staff/calendar?from=2026-09-01&to=2026-09-30', null],
            'list space media' => ['GET', '/api/staff/spaces/1/media', null],
            'upload space media' => ['POST', '/api/staff/spaces/1/media', null],
            'list event media' => ['GET', '/api/staff/events/1/media', null],
            'upload event media' => ['POST', '/api/staff/events/1/media', null],
            'edit media' => ['PATCH', '/api/staff/media/1', ['caption' => 'x']],
            'delete media' => ['DELETE', '/api/staff/media/1', null],
            'list enquiries' => ['GET', '/api/staff/enquiries?status=enquiry', null],
            'enquiry detail' => ['GET', '/api/staff/enquiries/GGG-2026-0447', null],
            'send an enquiry' => ['POST', '/api/enquiries', ['occasion' => 'Wedding']],
            'create a quotation' => ['POST', '/api/staff/quotations', ['bookingReference' => 'GGG-2026-0418', 'items' => [['label' => 'Hire', 'quantity' => 1, 'unitPrice' => 100]]]],
            'list quotations' => ['GET', '/api/staff/quotations?status=sent', null],
            'quotation detail' => ['GET', '/api/staff/quotations/GGG-Q-0034', null],
            'quotation versions' => ['GET', '/api/staff/quotations/GGG-Q-0034/versions', null],
            'my quotations' => ['GET', '/api/me/quotations', null],
            'my quotation' => ['GET', '/api/me/quotations/GGG-Q-0034', null],
            'accept a quotation' => ['POST', '/api/me/quotations/GGG-Q-0034/accept', null],
            'decline a quotation' => ['POST', '/api/me/quotations/GGG-Q-0034/decline', null],
            'ask for changes' => ['POST', '/api/me/quotations/GGG-Q-0034/request-changes', ['note' => 'Fewer guests']],
            'staff events list' => ['GET', '/api/staff/events', null],
            'create an event' => ['POST', '/api/staff/events', ['title' => 'Movie Night']],
            'staff event detail' => ['GET', '/api/staff/events/1', null],
            'edit an event' => ['PATCH', '/api/staff/events/1', ['title' => 'New']],
            'edit event tiers' => ['PUT', '/api/staff/events/1/tiers', ['tiers' => []]],
            'cancel an event' => ['POST', '/api/staff/events/1/cancel', ['reason' => 'Rain']],
            'event attendees' => ['GET', '/api/staff/events/1/attendees', null],
            'event paid orders' => ['GET', '/api/staff/events/1/orders', null],
            'gate scan' => ['POST', '/api/staff/events/1/scan', ['code' => 'abc']],
            'refund a ticket order' => ['POST', '/api/staff/ticket-orders/GGG-TKT-2026-0001/refund', ['reason' => 'Ill']],
            'resend e-tickets' => ['POST', '/api/staff/ticket-orders/GGG-TKT-2026-0001/resend', null],
            'my ticket orders' => ['GET', '/api/me/ticket-orders', null],
            'my tickets' => ['GET', '/api/me/tickets', null],
            'one of my orders' => ['GET', '/api/me/tickets/GGG-TKT-2026-0001', null],

            // Staff account, team, profile, favourites, and my-bookings routes
            'staff my account details' => ['PATCH', '/api/staff/me', ['name' => 'Lesedi', 'surname' => 'Kutumela', 'phone' => '']],
            'staff change password' => ['POST', '/api/staff/me/password', ['current' => 'a', 'next' => 'b2345678', 'again' => 'b2345678']],
            'list team' => ['GET', '/api/staff/team', null],
            'add to team' => ['POST', '/api/staff/team', ['name' => 'New', 'surname' => 'Person', 'email' => 'new@grandgala.co.za', 'role' => 'staff']],
            'change a team member' => ['PATCH', '/api/staff/team/1', ['isActive' => false]],
            'reset a team member\'s password' => ['POST', '/api/staff/team/1/reset-password', null],
            'my account' => ['GET', '/api/auth/me', null],
            'update my profile' => ['PATCH', '/api/me', ['name' => 'Thandi Mabuza', 'email' => 'thandi@email.com', 'phone' => '']],
            'list my favourites' => ['GET', '/api/me/favourites', null],
            'add a favourite' => ['POST', '/api/me/favourites/1', null],
            'remove a favourite' => ['DELETE', '/api/me/favourites/1', null],
            'my bookings' => ['GET', '/api/me/bookings', null],
            'my booking detail' => ['GET', '/api/me/bookings/GGG-2026-0417', null],
            'cancel my booking' => ['POST', '/api/me/bookings/GGG-2026-0417/cancel', null],

            // Stays, checkout, refunds, and promo codes
            'book a stay' => ['POST', '/api/bookings', ['spaceId' => 1, 'checkIn' => '2026-12-12', 'checkOut' => '2026-12-13', 'guests' => 2]],
            'ask for a refund' => ['POST', '/api/staff/payments/1/refunds', ['amount' => 100, 'reason' => 'Ill']],
            'record a refund' => ['PATCH', '/api/staff/refunds/1', ['gatewayReference' => 'PF-1']],
            'list promotions' => ['GET', '/api/staff/promotions', null],
            'add a promotion' => ['POST', '/api/staff/promotions', ['code' => 'SPRING', 'type' => 'fixed', 'value' => 10]],
            'change a promotion' => ['PATCH', '/api/staff/promotions/1', ['isActive' => false]],
        ];
    }

    #[DataProvider('staffRoutes')]
    public function testStaffRoutesNeedSignIn(string $method, string $path, ?array $body): void
    {
        $response = $this->send($method, $path, $body);

        self::assertSame(401, $response['status']);
        self::assertSame(['error' => 'Please sign in'], $response['body']);
    }
}
