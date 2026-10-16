<?php

declare(strict_types=1);

namespace GrandGala\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/*
 * QR for the gate. It encodes FRONTEND_URL/ticket/<code> and is redrawn each time, never stored.
 * SVG, so it does not need the GD extension.
 */
class TicketQr
{
    // Confirmation-page link, or the plain code if no website address is set.
    public static function payload(string $code): string
    {
        $website = rtrim(trim((string) ($_ENV['FRONTEND_URL'] ?? '')), '/');
        if ($website === '') {
            return $code;
        }

        return $website . '/ticket/' . $code;
    }

    // The QR code as an SVG image
    public static function svg(string $code): string
    {
        // 320px with a 16px margin. endroid/qr-code 6 has no setters; size is set here.
        $qrCode = new QrCode(data: self::payload($code), size: 320, margin: 16);

        $writer = new SvgWriter();
        $svg = $writer->write($qrCode)->getString();

        return $svg;
    }

    // The same image as a data: link, ready for an <img src="..."> in the frontend
    public static function dataUri(string $code): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($code));
    }
}
