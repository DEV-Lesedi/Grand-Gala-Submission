<?php

declare(strict_types=1);

namespace GrandGala\Services;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

/*
 * Transactional email. send() records every message for tests.
 * Failures are logged and swallowed so the booking still succeeds.
 */
class Mailer
{
    // Emails recorded since clear().
    private static array $sent = [];

    // For tests: when set, every send() throws with this message
    private static ?string $failure = null;

    public static function send(string $template, string $to, array $data): void
    {
        if (self::$failure !== null) {
            throw new RuntimeException(self::$failure);
        }
        self::$sent[] = ['template' => $template, 'to' => $to, 'data' => $data];

        if (($_ENV['APP_ENV'] ?? '') === 'testing') {
            return;
        }

        try {
            $message = self::render($template, $data);
            // SMTP ports are blocked here, so a Brevo key sends over HTTPS instead
            if (($_ENV['BREVO_API_KEY'] ?? '') !== '') {
                self::sendWithBrevoApi($to, $message);
                return;
            }
            $mailer = self::configured();
            $mailer->addAddress($to);
            $mailer->Subject = $message['subject'];
            $mailer->Body = $message['body'];
            $mailer->send();
        } catch (Throwable $error) {
            // Omit the address: logs outlive the guest details they should hold
            error_log('Mailer::send(' . $template . ') failed: ' . $error->getMessage());
        }
    }

    // For tests: make every send() fail with $message, or pass null to send normally again
    public static function failWith(?string $message): void
    {
        self::$failure = $message;
    }

    public static function sent(): array
    {
        return self::$sent;
    }

    public static function clear(): void
    {
        self::$sent = [];
    }

    // A template only uses $data; it never reads the database.
    private static function render(string $template, array $data): array
    {
        if ($template === 'enquiry_received') {
            return [
                'subject' => 'We got your enquiry ' . ($data['reference'] ?? ''),
                'body' => 'Thanks ' . ($data['guestName'] ?? 'there') . ', we received your enquiry for '
                    . ($data['occasion'] ?? 'your event') . '. We\'ll send you a quotation soon.',
            ];
        }
        if ($template === 'enquiry_staff') {
            $spaces = '';
            if (isset($data['spaces']) && is_array($data['spaces'])) {
                $spaces = implode(', ', $data['spaces']);
            }

            return [
                'subject' => 'New enquiry ' . ($data['reference'] ?? '') . ': ' . ($data['occasion'] ?? 'an event'),
                'body' => ($data['guestName'] ?? 'A guest') . ' (' . ($data['guestEmail'] ?? '') . ', ' . ($data['guestPhone'] ?? '')
                    . ') asked about ' . ($data['occasion'] ?? 'an event') . ' on ' . ($data['preferredDate'] ?? 'a date to agree')
                    . ' for ' . ($data['estimatedGuests'] ?? '?') . ' guests. Spaces: ' . $spaces . '.',
            ];
        }
        if ($template === 'quotation_sent') {
            return [
                'subject' => 'Your quotation from Grand Gala Gardens',
                'body' => 'Your quotation ' . ($data['reference'] ?? '') . ' is ready: R' . ($data['total'] ?? '0')
                    . '. It\'s valid until ' . ($data['validUntil'] ?? 'the date shown on the quotation') . '.',
            ];
        }
        if ($template === 'payment_link') {
            $link = rtrim($_ENV['FRONTEND_URL'] ?? '', '/') . '/checkout?reference=' . ($data['bookingReference'] ?? '');

            return [
                'subject' => 'Complete your payment',
                'body' => 'Pay for ' . ($data['bookingReference'] ?? 'your booking') . ' (R' . ($data['total'] ?? '0')
                    . ') before ' . ($data['holdExpires'] ?? 'the hold runs out') . ': ' . $link,
            ];
        }
        if ($template === 'booking_confirmed') {
            $reference = $data['bookingReference'] ?? $data['reference'] ?? '';

            return [
                'subject' => 'Booking confirmed: ' . $reference,
                'body' => 'Your booking ' . $reference . ' is confirmed. We look forward to welcoming you.',
            ];
        }
        if ($template === 'e_tickets') {
            $order = $data['order'] ?? [];
            $count = 0;
            if (isset($order['tickets']) && is_array($order['tickets'])) {
                $count = count($order['tickets']);
            }

            return [
                'subject' => 'Your e-tickets',
                'body' => 'Your ' . $count . ' ticket(s) for ' . ($order['eventTitle'] ?? 'the event')
                    . ' are in order ' . ($data['orderReference'] ?? '') . '. Show the QR codes at the gate.',
            ];
        }
        if ($template === 'password_reset') {
            return [
                'subject' => 'Reset your password',
                'body' => 'Reset your password here: ' . ($data['resetUrl'] ?? '')
                    . "\nThis link expires in " . ($data['expiresInMinutes'] ?? 60) . ' minutes.',
            ];
        }
        if ($template === 'staff_invite') {
            return [
                'subject' => 'You\'ve been added to the Grand Gala Gardens team',
                'body' => ($data['invitedBy'] ?? 'An admin') . ' added you as ' . ($data['role'] ?? 'staff')
                    . '. Choose your password here (the link works for ' . ($data['expiresInHours'] ?? 72) . ' hours): '
                    . ($data['inviteUrl'] ?? ''),
            ];
        }

        // Unknown template is logged by send(), not a reason to fail the booking.
        throw new RuntimeException('Unknown email template: ' . $template);
    }

    // Brevo payload for one email. Public so tests can check it.
    public static function brevoPayload(string $to, array $message): array
    {
        return [
            'sender' => ['name' => 'Grand Gala Gardens', 'email' => $_ENV['MAIL_FROM'] ?? 'no-reply@grandgala.co.za'],
            'to' => [['email' => $to]],
            'subject' => $message['subject'],
            'textContent' => $message['body'],
        ];
    }

    // Throws if Brevo rejects it; send() logs that. 10s timeout so the page doesn't hang.
    private static function sendWithBrevoApi(string $to, array $message): void
    {
        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(self::brevoPayload($to, $message), JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $_ENV['BREVO_API_KEY'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $answer = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $problem = curl_error($curl);

        if ($answer === false) {
            throw new RuntimeException('Brevo could not be reached: ' . $problem);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Brevo answered ' . $status . ': ' . $answer);
        }
    }

    // No username: Mailpit, no TLS. With one: STARTTLS login. Public for tests.
    public static function configured(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $_ENV['MAIL_HOST'] ?? '127.0.0.1';
        $mailer->Port = (int) ($_ENV['MAIL_PORT'] ?? 1025); // Mailpit's default SMTP port
        $mailer->Timeout = 10; // 5-minute default froze the page when SMTP was blocked

        $username = $_ENV['MAIL_USERNAME'] ?? '';
        if ($username !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $_ENV['MAIL_PASSWORD'] ?? '';
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPAuth = false;
            $mailer->SMTPAutoTLS = false;
        }
        $mailer->setFrom($_ENV['MAIL_FROM'] ?? 'no-reply@grandgala.co.za', 'Grand Gala Gardens');

        return $mailer;
    }
}
