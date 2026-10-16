<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

/* Detail that lives outside this booking row. Read only.
 * Prices are the ones frozen at booking, never today's rates.
 */
class BookingDetails
{
    // Each space, then extras. Cancelled extras are left out.
    public static function stayLines(int $bookingId): array
    {
        $lines = [];

        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_name, ss.ss_nights, ss.ss_line_total
             FROM stay_space ss
             JOIN stay_booking sb ON sb.sb_id = ss.ss_sb_id
             JOIN space sp ON sp.sp_id = ss.ss_sp_id
             WHERE sb.sb_b_id = ?
             ORDER BY ss.ss_id'
        );
        $statement->execute([$bookingId]);
        foreach ($statement->fetchAll() as $row) {
            $nights = (int) $row['ss_nights'];
            $label = $row['sp_name'] . ' · ' . $nights . ' night';
            if ($nights !== 1) {
                $label = $label . 's';
            }
            $lines[] = ['label' => $label, 'amount' => (float) $row['ss_line_total']];
        }

        $statement = Db::pdo()->prepare(
            "SELECT a.a_name, ab.ab_quantity, ab.ab_line_total
             FROM amenity_booking ab
             JOIN amenity a ON a.a_id = ab.ab_a_id
             WHERE ab.ab_b_id = ? AND ab.ab_status <> 'cancelled'
             ORDER BY ab.ab_id"
        );
        $statement->execute([$bookingId]);
        foreach ($statement->fetchAll() as $row) {
            // Name only, or name times quantity when more than one.
            $label = $row['a_name'];
            if ((int) $row['ab_quantity'] > 1) {
                $label = $label . ' × ' . $row['ab_quantity'];
            }
            $lines[] = ['label' => $label, 'amount' => (float) $row['ab_line_total']];
        }

        return $lines;
    }

    // Each space, then catering. Unpriced or cancelled catering is left out.
    // Décor has no price of its own.
    public static function eventLines(int $bookingId): array
    {
        $lines = [];

        $statement = Db::pdo()->prepare(
            'SELECT sp.sp_name, es.es_price_snapshot
             FROM event_space es
             JOIN event_booking eb ON eb.eb_id = es.es_eb_id
             JOIN space sp ON sp.sp_id = es.es_sp_id
             WHERE eb.eb_b_id = ?
             ORDER BY es.es_id'
        );
        $statement->execute([$bookingId]);
        foreach ($statement->fetchAll() as $row) {
            $lines[] = ['label' => $row['sp_name'], 'amount' => (float) $row['es_price_snapshot']];
        }

        $statement = Db::pdo()->prepare(
            "SELECT c_headcount, c_line_total FROM catering
             WHERE c_b_id = ? AND c_status <> 'cancelled' AND c_line_total IS NOT NULL
             ORDER BY c_id"
        );
        $statement->execute([$bookingId]);
        foreach ($statement->fetchAll() as $row) {
            $label = 'Catering';
            if ($row['c_headcount'] !== null) {
                $label = 'Catering · ' . $row['c_headcount'] . ' guests';
            }
            $lines[] = ['label' => $label, 'amount' => (float) $row['c_line_total']];
        }

        return $lines;
    }

    // paidAt and method are null if unpaid. Only refunds that went through count.
    public static function payment(int $bookingId): array
    {
        $statement = Db::pdo()->prepare(
            'SELECT p_paid_at, p_method FROM payment
             WHERE p_b_id = ? AND p_paid_at IS NOT NULL
             ORDER BY p_paid_at DESC LIMIT 1'
        );
        $statement->execute([$bookingId]);
        $paid = $statement->fetch();

        $statement = Db::pdo()->prepare(
            "SELECT SUM(rf.rf_amount) AS refunded, MAX(rf.rf_date_created) AS refunded_at
             FROM refund rf
             JOIN payment p ON p.p_id = rf.rf_p_id
             WHERE p.p_b_id = ? AND rf.rf_status = 'processed'"
        );
        $statement->execute([$bookingId]);
        $refunds = $statement->fetch();

        $paidAt = null;
        $method = null;
        if ($paid !== false) {
            $paidAt = $paid['p_paid_at'];
            $method = $paid['p_method'];
        }

        return [
            'paidAt' => $paidAt,
            'method' => $method,
            'refunded' => (float) $refunds['refunded'], // null (no refunds) becomes 0.0
            'refundedAt' => $refunds['refunded_at'],
        ];
    }

    // null if the booking has no quotation.
    public static function latestQuotation(int $bookingId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT q_status, q_amount, q_valid_until,
                    (SELECT COUNT(*) FROM quotation WHERE q_b_id = ?) AS version
             FROM quotation WHERE q_b_id = ?
             ORDER BY q_id DESC LIMIT 1'
        );
        $statement->execute([$bookingId, $bookingId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'version' => (int) $row['version'],
            'status' => $row['q_status'],
            'amount' => (float) $row['q_amount'],
            'validUntil' => $row['q_valid_until'],
        ];
    }

    // null if this booking has no enquiry.
    public static function enquiry(int $bookingId): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT ee_preferred_date, ee_alternate_date, ee_estimated_guests, ee_occasion, ee_requirements,
                    ee_wants_catering, ee_wants_decor
             FROM event_enquiry WHERE ee_b_id = ?'
        );
        $statement->execute([$bookingId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $estimatedGuests = null;
        if ($row['ee_estimated_guests'] !== null) {
            $estimatedGuests = (int) $row['ee_estimated_guests'];
        }

        return [
            'preferredDate' => $row['ee_preferred_date'],
            'alternateDate' => $row['ee_alternate_date'],
            'estimatedGuests' => $estimatedGuests,
            'occasion' => $row['ee_occasion'],
            'requirements' => $row['ee_requirements'],
            'wantsCatering' => $row['ee_wants_catering'] === 1,
            'wantsDecor' => $row['ee_wants_decor'] === 1,
        ];
    }
}
