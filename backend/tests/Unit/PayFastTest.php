<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\PaymentGateway\PayFast;
use GrandGala\Utils\HttpError;
use PHPUnit\Framework\TestCase;

// PayFast's signature, address checks, and validation answer. No database.
class PayFastTest extends TestCase
{
    private const KEYS = ['PAYFAST_PASSPHRASE', 'PAYFAST_VALID_IPS', 'PAYFAST_VALID_HOSTS', 'PAYFAST_MERCHANT_ID'];

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        PayFast::fakeValidationAnswer(null);
    }

    public function testSignatureIgnoresExistingSignatureAndIncludesPassphrase(): void
    {
        $_ENV['PAYFAST_PASSPHRASE'] = 'test phrase';
        $fields = ['merchant_id' => '10000100', 'amount' => '10.00', 'item_name' => 'Grand Gala stay'];
        $signature = PayFast::signature($fields);
        $fields['signature'] = $signature;

        // PayFast's rule, worked by hand: URL-encoded pairs, then the passphrase, then MD5.
        self::assertSame(md5('merchant_id=10000100&amount=10.00&item_name=Grand+Gala+stay&passphrase=test+phrase'), $signature);
        self::assertTrue(PayFast::hasValidSignature($fields));
        $fields['amount'] = '11.00';
        self::assertFalse(PayFast::hasValidSignature($fields));
    }

    // PayFast signs its payment notice over every field it posts, empty ones too (custom_str1=, item_description=),
    // in the order posted. The checkout form's rule leaves empty fields out, so it can't check a notice.
    public function testANoticeIsCheckedOverEveryFieldIncludingEmptyOnes(): void
    {
        $_ENV['PAYFAST_PASSPHRASE'] = 'test phrase';
        $notice = ['m_payment_id' => 'GGG-2026-0451-P-1', 'pf_payment_id' => '123', 'payment_status' => 'COMPLETE',
            'item_name' => 'Grand Gala stay', 'item_description' => '', 'amount_gross' => '890.00', 'custom_str1' => '',
            'name_first' => 'Tom', 'merchant_id' => '10049816'];
        // Worked by hand the way PayFast does it
        $notice['signature'] = md5('m_payment_id=GGG-2026-0451-P-1&pf_payment_id=123&payment_status=COMPLETE'
            . '&item_name=Grand+Gala+stay&item_description=&amount_gross=890.00&custom_str1=&name_first=Tom'
            . '&merchant_id=10049816&passphrase=test+phrase');

        self::assertTrue(PayFast::hasValidSignature($notice));

        // The same notice signed with the empty fields left out (the old check) doesn't match
        $notice['signature'] = PayFast::signature($notice);
        self::assertFalse(PayFast::hasValidSignature($notice));
    }

    public function testASignatureMadeWithoutThePassphraseIsRefused(): void
    {
        unset($_ENV['PAYFAST_PASSPHRASE']);
        $fields = ['merchant_id' => '10000100', 'amount' => '10.00'];
        $fields['signature'] = PayFast::noticeSignature($fields);

        $_ENV['PAYFAST_PASSPHRASE'] = 'test phrase';
        self::assertFalse(PayFast::hasValidSignature($fields));
    }

    public function testSourceIpCanMatchAnExactAddressOrCidr(): void
    {
        $_ENV['PAYFAST_VALID_IPS'] = '192.0.2.1, 198.51.100.0/24';

        self::assertTrue(PayFast::sourceIpAllowed('192.0.2.1'));
        self::assertTrue(PayFast::sourceIpAllowed('198.51.100.42'));
        self::assertFalse(PayFast::sourceIpAllowed('198.51.101.42'));
        self::assertFalse(PayFast::sourceIpAllowed('not an ip'));
    }

    // Check the sender against what PayFast's hostnames point to now; they change over time.
    public function testSourceIpCanMatchAHostnameLookedUpNow(): void
    {
        unset($_ENV['PAYFAST_VALID_IPS']);
        $_ENV['PAYFAST_VALID_HOSTS'] = 'no-such-host.invalid, localhost';

        self::assertTrue(PayFast::sourceIpAllowed('127.0.0.1'));
        self::assertFalse(PayFast::sourceIpAllowed('192.0.2.1'));
    }

    public function testSourceIpIsRejectedWhenAllowlistIsMissing(): void
    {
        unset($_ENV['PAYFAST_VALID_IPS'], $_ENV['PAYFAST_VALID_HOSTS']);

        self::assertFalse(PayFast::sourceIpAllowed('127.0.0.1'));
    }

    public function testOnlyAValidAnswerFromPayFastPasses(): void
    {
        PayFast::fakeValidationAnswer('VALID');
        PayFast::validateWithGateway(['m_payment_id' => 'x']);

        PayFast::fakeValidationAnswer('INVALID');
        $this->expectException(HttpError::class);
        PayFast::validateWithGateway(['m_payment_id' => 'x']);
    }

    public function testAMissingMerchantIdIsA503(): void
    {
        unset($_ENV['PAYFAST_MERCHANT_ID']);

        try {
            PayFast::merchantId();
            self::fail('Expected a 503');
        } catch (HttpError $error) {
            self::assertSame(503, $error->status());
        }
    }
}
