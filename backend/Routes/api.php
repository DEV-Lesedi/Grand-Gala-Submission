<?php

declare(strict_types=1);

use GrandGala\API\Admin\DashboardController;
use GrandGala\API\Admin\MyAccountController;
use GrandGala\API\Admin\TeamController;
use GrandGala\API\Amenities\AmenitiesController;
use GrandGala\API\Auth\AuthController;
use GrandGala\API\Auth\StaffAuthController;
use GrandGala\API\Availability\AvailabilityController;
use GrandGala\API\Bookings\EventAccommodationController;
use GrandGala\API\Bookings\StaffBookingsController;
use GrandGala\API\Bookings\StayController;
use GrandGala\API\Enquiries\EnquiriesController;
use GrandGala\API\Enquiries\StaffEnquiriesController;
use GrandGala\API\Events\EventController;
use GrandGala\API\Gallery\GalleryController;
use GrandGala\API\HealthController;
use GrandGala\API\Me\MeController;
use GrandGala\API\Media\MediaController;
use GrandGala\API\Payments\LocalPaymentController;
use GrandGala\API\Payments\PaymentController;
use GrandGala\API\Promotions\PromotionController;
use GrandGala\API\Quotations\GuestQuotationsController;
use GrandGala\API\Quotations\StaffQuotationsController;
use GrandGala\API\Spaces\BlackoutsController;
use GrandGala\API\Spaces\RatesController;
use GrandGala\API\Spaces\SpacesController;
use GrandGala\API\Spaces\StaffSpacesController;
use GrandGala\API\Tickets\TicketController;
use GrandGala\Utils\Router;

$router = new Router();

// Each route: method, path, handler, and optional sign-in checks ('user', 'staff', 'role:admin')
$router->add('GET', '/api/health', new HealthController());

$spaces = new SpacesController();
$router->add('GET', '/api/spaces', [$spaces, 'list']);
$router->add('GET', '/api/spaces/:id', [$spaces, 'show']);

$router->add('GET', '/api/availability', [new AvailabilityController(), 'calendar']);

$auth = new AuthController();
$router->add('POST', '/api/auth/register', [$auth, 'register']);
$router->add('POST', '/api/auth/login', [$auth, 'login']);
$router->add('POST', '/api/auth/logout', [$auth, 'logout']);
$router->add('GET', '/api/auth/me', [$auth, 'me'], ['user']);
$router->add('POST', '/api/auth/forgot-password', [$auth, 'forgotPassword']);
$router->add('POST', '/api/auth/reset-password', [$auth, 'resetPassword']);

$router->add('POST', '/api/staff/auth/login', [new StaffAuthController(), 'login']);

$me = new MeController();
$router->add('PATCH', '/api/me', [$me, 'updateProfile'], ['user']);
$router->add('POST', '/api/me/password', [$me, 'changePassword'], ['user']);
$router->add('GET', '/api/me/favourites', [$me, 'listFavourites'], ['user']);
$router->add('POST', '/api/me/favourites/:spaceId', [$me, 'addFavourite'], ['user']);
$router->add('DELETE', '/api/me/favourites/:spaceId', [$me, 'removeFavourite'], ['user']);
$router->add('GET', '/api/me/bookings', [$me, 'bookings'], ['user']);
$router->add('GET', '/api/me/bookings/:reference', [$me, 'bookingDetail'], ['user']);
$router->add('POST', '/api/me/bookings/:reference/cancel', [$me, 'cancelBooking'], ['user']);

$router->add('GET', '/api/gallery', [new GalleryController(), 'list']);

$myAccount = new MyAccountController();
$router->add('PATCH', '/api/staff/me', [$myAccount, 'updateDetails'], ['staff']);
$router->add('POST', '/api/staff/me/password', [$myAccount, 'changePassword'], ['staff']);

$team = new TeamController();
$router->add('GET', '/api/staff/team', [$team, 'list'], ['role:admin']);
$router->add('POST', '/api/staff/team', [$team, 'add'], ['role:admin']);
$router->add('PATCH', '/api/staff/team/:id', [$team, 'update'], ['role:admin']);
$router->add('POST', '/api/staff/team/:id/reset-password', [$team, 'resetPassword'], ['role:admin']);

$staffSpaces = new StaffSpacesController();
$router->add('GET', '/api/staff/spaces', [$staffSpaces, 'list'], ['staff']);
$router->add('POST', '/api/staff/spaces', [$staffSpaces, 'add'], ['staff']);
$router->add('PATCH', '/api/staff/spaces/:id', [$staffSpaces, 'update'], ['staff']);

$rates = new RatesController();
$router->add('GET', '/api/staff/rate-card', [$rates, 'rateCard'], ['staff']);
$router->add('POST', '/api/staff/spaces/:id/rates', [$rates, 'add'], ['staff']);
$router->add('GET', '/api/staff/spaces/:id/rates/overlaps', [$rates, 'overlaps'], ['staff']);
$router->add('POST', '/api/staff/rates/:id/cancel', [$rates, 'cancel'], ['staff']);
$router->add('POST', '/api/staff/rates/:id/end-now', [$rates, 'endNow'], ['staff']);

$blackouts = new BlackoutsController();
$router->add('POST', '/api/staff/blackouts', [$blackouts, 'add'], ['staff']);
$router->add('DELETE', '/api/staff/blackouts/:id', [$blackouts, 'delete'], ['staff']);

$amenities = new AmenitiesController();
$router->add('GET', '/api/staff/amenities', [$amenities, 'list'], ['staff']);
$router->add('POST', '/api/staff/amenities', [$amenities, 'add'], ['staff']);
$router->add('PATCH', '/api/staff/amenities/:id', [$amenities, 'update'], ['staff']);

$staffBookings = new StaffBookingsController();
$router->add('GET', '/api/staff/bookings', [$staffBookings, 'list'], ['staff']);
$router->add('GET', '/api/staff/bookings/:reference', [$staffBookings, 'detail'], ['staff']);

// Rooms for a private event's guests
$eventAccommodation = new EventAccommodationController();
$router->add('GET', '/api/staff/bookings/:reference/accommodation', [$eventAccommodation, 'list'], ['staff']);
$router->add('POST', '/api/staff/bookings/:reference/accommodation', [$eventAccommodation, 'add'], ['staff']);
$router->add('DELETE', '/api/staff/bookings/:reference/accommodation/:id', [$eventAccommodation, 'remove'], ['staff']);

$dashboard = new DashboardController();
$router->add('GET', '/api/staff/today', [$dashboard, 'today'], ['staff']);
$router->add('GET', '/api/staff/nav-counts', [$dashboard, 'navCounts'], ['staff']);
$router->add('GET', '/api/staff/calendar', [$dashboard, 'calendar'], ['staff']);

$router->add('POST', '/api/enquiries', [new EnquiriesController(), 'submit'], ['user']);
$staffEnquiries = new StaffEnquiriesController();
$router->add('GET', '/api/staff/enquiries', [$staffEnquiries, 'list'], ['staff']);
$router->add('GET', '/api/staff/enquiries/:reference', [$staffEnquiries, 'detail'], ['staff']);

$staffQuotations = new StaffQuotationsController();
$router->add('POST', '/api/staff/quotations', [$staffQuotations, 'create'], ['staff']);
$router->add('GET', '/api/staff/quotations', [$staffQuotations, 'list'], ['staff']);
$router->add('GET', '/api/staff/quotations/:reference', [$staffQuotations, 'detail'], ['staff']);
$router->add('GET', '/api/staff/quotations/:reference/versions', [$staffQuotations, 'versions'], ['staff']);

$guestQuotations = new GuestQuotationsController();
$router->add('GET', '/api/me/quotations', [$guestQuotations, 'list'], ['user']);
$router->add('GET', '/api/me/quotations/:reference', [$guestQuotations, 'detail'], ['user']);
$router->add('POST', '/api/me/quotations/:reference/accept', [$guestQuotations, 'accept'], ['user']);
$router->add('POST', '/api/me/quotations/:reference/decline', [$guestQuotations, 'decline'], ['user']);
$router->add('POST', '/api/me/quotations/:reference/request-changes', [$guestQuotations, 'requestChanges'], ['user']);

$events = new EventController();
$router->add('GET', '/api/events', [$events, 'publicList']);
$router->add('GET', '/api/events/:id', [$events, 'publicDetail']);
$router->add('GET', '/api/staff/events', [$events, 'staffList'], ['staff']);
$router->add('POST', '/api/staff/events', [$events, 'create'], ['staff']);
$router->add('GET', '/api/staff/events/:id', [$events, 'staffDetail'], ['staff']);
$router->add('PATCH', '/api/staff/events/:id', [$events, 'update'], ['staff']);
$router->add('PUT', '/api/staff/events/:id/tiers', [$events, 'saveTiers'], ['staff']);
$router->add('POST', '/api/staff/events/:id/cancel', [$events, 'cancel'], ['staff']);
$router->add('GET', '/api/staff/events/:id/attendees', [$events, 'attendees'], ['staff']);
$router->add('GET', '/api/staff/events/:id/orders', [$events, 'orders'], ['staff']);
$router->add('POST', '/api/staff/events/:id/scan', [$events, 'scan'], ['staff']);

$tickets = new TicketController();
$router->add('POST', '/api/ticket-orders', [$tickets, 'create'], ['optional-user']);
$router->add('GET', '/api/ticket-orders/:reference', [$tickets, 'detail'], ['optional-user']);
// Public ticket page: validity, holder, and event. No private fields.
$router->add('GET', '/api/tickets/:code', [$tickets, 'confirmation']);
$router->add('POST', '/api/ticket-orders/:reference/cancel', [$tickets, 'cancel'], ['optional-user']);
$router->add('GET', '/api/ticket-orders/:reference/tickets/:code/qr', [$tickets, 'qr'], ['optional-user']);
$router->add('GET', '/api/me/ticket-orders', [$tickets, 'myOrders'], ['user']);
$router->add('GET', '/api/me/tickets', [$tickets, 'myTickets'], ['user']);
$router->add('GET', '/api/me/tickets/:reference', [$tickets, 'myOrder'], ['user']);
$router->add('POST', '/api/staff/ticket-orders/:reference/refund', [$tickets, 'refund'], ['staff']);
$router->add('POST', '/api/staff/ticket-orders/:reference/resend', [$tickets, 'resend'], ['staff']);

$media = new MediaController();
$router->add('GET', '/api/staff/spaces/:id/media', [$media, 'listForSpace'], ['staff']);
$router->add('POST', '/api/staff/spaces/:id/media', [$media, 'uploadForSpace'], ['staff']);
$router->add('GET', '/api/staff/events/:id/media', [$media, 'listForEvent'], ['staff']);
$router->add('POST', '/api/staff/events/:id/media', [$media, 'uploadForEvent'], ['staff']);
$router->add('PATCH', '/api/staff/media/:id', [$media, 'update'], ['staff']);
$router->add('DELETE', '/api/staff/media/:id', [$media, 'delete'], ['staff']);

$stays = new StayController();
$router->add('POST', '/api/stays/price', [$stays, 'price']);
$router->add('POST', '/api/bookings', [$stays, 'create'], ['user']);

// PayFast notify has no sign-in; checked by IP, signature, and PayFast's reply.
$payments = new PaymentController();
$router->add('POST', '/api/payments/checkout', [$payments, 'checkout'], ['optional-user']);
$router->add('POST', '/api/payments/payfast/notify', [$payments, 'payFastNotify']);
$router->add('POST', '/api/payments/payfast/cancel', [$payments, 'payFastCancel']);
$router->add('POST', '/api/staff/payments/:id/refunds', [$payments, 'requestRefund'], ['staff']);
$router->add('PATCH', '/api/staff/refunds/:id', [$payments, 'markRefundProcessed'], ['role:admin']);

$promotions = new PromotionController();
$router->add('GET', '/api/staff/promotions', [$promotions, 'list'], ['role:admin']);
$router->add('POST', '/api/staff/promotions', [$promotions, 'create'], ['role:admin']);
$router->add('PATCH', '/api/staff/promotions/:id', [$promotions, 'update'], ['role:admin']);
$router->add('POST', '/api/promotions/validate', [$promotions, 'validate']);

// Completes a test payment through the real notify path. Local only.
if (($_ENV['APP_ENV'] ?? '') === 'local') {
    $router->add('POST', '/api/dev/payfast/complete', [new LocalPaymentController(), 'complete']);
}

return $router;
