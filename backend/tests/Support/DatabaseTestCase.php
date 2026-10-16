<?php

declare(strict_types=1);

namespace GrandGala\Tests\Support;

use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use PHPUnit\Framework\TestCase;

// Shared test DB. insert() or track() every row; tearDown() deletes newest first, demo data stays.
abstract class DatabaseTestCase extends TestCase
{
    // Every row this test made, e.g. [['table' => 'venue', 'key' => 'v_id', 'id' => 7], ...]
    private array $created = [];

    // The reference counters (booking, quotation, ticket_order) as they were before this test
    private array $savedCounters = [];

    protected function setUp(): void
    {
        parent::setUp();
        // References::next uses the shared counters, so each test restores them afterwards.
        $this->savedCounters = Db::pdo()->query('SELECT rs_kind, rs_year, rs_next FROM reference_sequence')->fetchAll();
    }

    protected function tearDown(): void
    {
        // Newest first. A promo use does not cascade (migration 010), so delete it first.
        foreach (array_reverse($this->created) as $row) {
            if ($row['table'] === 'booking') {
                Db::pdo()->prepare('DELETE FROM promo_use WHERE pu_booking_id = ?')->execute([$row['id']]);
            }
            if ($row['table'] === 'ticket_order') {
                Db::pdo()->prepare('DELETE FROM promo_use WHERE pu_to_id = ?')->execute([$row['id']]);
            }
            $sql = 'DELETE FROM `' . $row['table'] . '` WHERE `' . $row['key'] . '` = ?';
            Db::pdo()->prepare($sql)->execute([$row['id']]);
        }
        $this->created = [];

        $restore = Db::pdo()->prepare('UPDATE reference_sequence SET rs_year = ?, rs_next = ? WHERE rs_kind = ?');
        foreach ($this->savedCounters as $counter) {
            $restore->execute([$counter['rs_year'], $counter['rs_next'], $counter['rs_kind']]);
        }
    }

    // Inserts a row, remembers it for tearDown(), and returns the new id.
    protected function insert(string $table, string $key, array $values): int
    {
        $columns = [];
        $marks = [];
        foreach (array_keys($values) as $column) {
            $columns[] = '`' . $column . '`';
            $marks[] = '?';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')';
        Db::pdo()->prepare($sql)->execute(array_values($values));
        $id = (int) Db::pdo()->lastInsertId();
        $this->created[] = ['table' => $table, 'key' => $key, 'id' => $id];

        return $id;
    }

    // Registers a row created by the code under test, so it's deleted after the test too
    protected function track(string $table, string $key, int $id): void
    {
        $this->created[] = ['table' => $table, 'key' => $key, 'id' => $id];
    }

    // Changes a row this test created, e.g. ['sr_is_active' => 0].
    protected function update(string $table, string $key, int $id, array $values): void
    {
        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = '`' . $column . '` = ?';
        }

        $parameters = array_values($values);
        $parameters[] = $id;
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $key . '` = ?';
        Db::pdo()->prepare($sql)->execute($parameters);
    }

    // Own venue unless sp_v_id is passed. Names start with [test] so leftovers are easy to spot.
    protected function createSpace(array $columns = []): int
    {
        $venueId = $columns['sp_v_id'] ?? null;
        if ($venueId === null) {
            $venueId = $this->createVenue();
        }

        // Anything in $columns replaces these defaults, e.g. ['sp_is_active' => 0]
        $defaults = [
            'sp_v_id' => $venueId,
            'sp_name' => '[test] space',
            'sp_type' => 'villa',
            'sp_capacity' => 10,
        ];

        return $this->insert('space', 'sp_id', array_merge($defaults, $columns));
    }

    // Adds a price to a space. Give $validTo for a seasonal price, or null for a normal one.
    protected function addRate(int $spaceId, string $basis, float $price, string $from, ?string $to, string $label = 'Standard'): int
    {
        return $this->insert('space_rate', 'sr_id', [
            'sr_sp_id' => $spaceId,
            'sr_basis' => $basis,
            'sr_price' => $price,
            'sr_label' => $label,
            'sr_valid_from' => $from,
            'sr_valid_to' => $to,
        ]);
    }

    protected function createVenue(): int
    {
        return $this->insert('venue', 'v_id', ['v_name' => '[test] venue', 'v_capacity' => 100]);
    }

    // Creates a guest account to own test bookings
    protected function createGuest(): int
    {
        $customerType = (int) Db::pdo()->query("SELECT ut_id FROM user_type WHERE ut_name = 'customer'")->fetchColumn();

        return $this->insert('app_user', 'u_id', [
            'u_ut_id' => $customerType,
            'u_name' => '[test]',
            'u_surname' => 'Guest',
            'u_email' => 'test-' . bin2hex(random_bytes(6)) . '@example.com',
            'u_password_hash' => 'not-a-real-hash',
        ]);
    }

    // Nights from $checkIn up to, but not including, $checkOut.
    protected function createStay(int $spaceId, string $checkIn, string $checkOut, string $status, ?string $holdExpires = null): int
    {
        $bookingId = $this->createBooking('stay', $status, $holdExpires);
        $stayId = $this->insert('stay_booking', 'sb_id', [
            'sb_b_id' => $bookingId, 'sb_check_in' => $checkIn, 'sb_check_out' => $checkOut, 'sb_guests' => 2,
        ]);
        $this->insert('stay_space', 'ss_id', [
            'ss_sb_id' => $stayId, 'ss_sp_id' => $spaceId, 'ss_nights' => 1, 'ss_price_snapshot' => 0, 'ss_line_total' => 0,
        ]);

        return $bookingId;
    }

    // $visibility is 'private' (a wedding or party) or 'public' (ticketed).
    protected function createEvent(int $spaceId, string $date, string $status, ?string $holdExpires = null, string $visibility = 'private'): array
    {
        $bookingId = $this->createBooking('event', $status, $holdExpires);
        $venueId = (int) Db::pdo()->query('SELECT sp_v_id FROM space WHERE sp_id = ' . $spaceId)->fetchColumn();
        $eventId = $this->insert('event_booking', 'eb_id', [
            'eb_b_id' => $bookingId, 'eb_v_id' => $venueId, 'eb_event_date' => $date,
            'eb_start_time' => '10:00:00', 'eb_end_time' => '16:00:00', 'eb_visibility' => $visibility,
        ]);
        $this->insert('event_space', 'es_id', ['es_eb_id' => $eventId, 'es_sp_id' => $spaceId, 'es_price_snapshot' => 0]);

        return ['bookingId' => $bookingId, 'eventId' => $eventId];
    }

    private function createBooking(string $type, string $status, ?string $holdExpires): int
    {
        $typeId = (int) Db::pdo()->query("SELECT bt_id FROM booking_type WHERE bt_name = '" . $type . "'")->fetchColumn();

        return $this->insert('booking', 'b_id', [
            'b_reference' => 'TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'b_bt_id' => $typeId,
            'b_u_id' => $this->createGuest(),
            'b_status' => $status,
            'b_hold_expires_at' => $holdExpires,
        ]);
    }

    // Runs $work and returns the HttpError it throws. Fails the test if nothing is thrown.
    protected function errorFrom(callable $work): HttpError
    {
        try {
            $work();
        } catch (HttpError $error) {
            return $error;
        }
        self::fail('Expected an HttpError');
    }

    // Separate PHP process (own DB connection) for a second actor. Does not wait.
    protected function startScript(string $script, array $arguments): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/Support/' . $script];
        foreach ($arguments as $argument) {
            $command[] = (string) $argument;
        }
        // Keep what the script prints (1) and its errors (2)
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        return ['process' => $process, 'pipes' => $pipes];
    }

    // Waits for a script from startScript() to finish and returns what it printed
    protected function waitForScript(array $script): string
    {
        $output = trim((string) stream_get_contents($script['pipes'][1]));
        $errors = trim((string) stream_get_contents($script['pipes'][2]));
        proc_close($script['process']);
        if ($errors !== '') {
            self::fail('The script failed: ' . $errors);
        }

        return $output;
    }
}
