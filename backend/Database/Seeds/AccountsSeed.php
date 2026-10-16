<?php

declare(strict_types=1);

namespace GrandGala\Database\Seeds;

use GrandGala\Utils\Db;

/* Demo accounts and gallery rows. Safe to re-run: only adds what is missing. */
class AccountsSeed
{
    // Includes superadmin. customer, staff, and admin may already exist.
    private const USER_TYPES = ['customer', 'staff', 'admin', 'superadmin'];

    // name, surname, email, phone, role, isActive. Password is DEMO_ACCOUNT_PASSWORD.
    private const STAFF_ACCOUNTS = [
        ['Lesedi', 'Kutumela', 'lesedi@grandgala.co.za', '+27 82 555 0142', 'superadmin', true],
        ['Naledi', 'Khumalo', 'naledi@grandgala.co.za', '+27 72 555 0163', 'admin', true],
        ['Sipho', 'Ndlovu', 'sipho@grandgala.co.za', '+27 71 555 0187', 'staff', true],
        ['Megan', 'de Villiers', 'megan@grandgala.co.za', null, 'staff', false],
        ['Thandi', 'Mabuza', 'thandi@email.com', '+27 83 555 0110', 'customer', true],
    ];

    // title, shortTitle, spaceLabel, category, url, isWide.
    private const GALLERY_ITEMS = [
        ['The Villa from the lower lawn', 'The Villa', 'The Villa', 'villa', '/photos/villa.jpg', true],
        ['Villa lounge onto the pool', 'Villa lounge', 'The Villa', 'villa', '/photos/lounge.jpg', false],
        ['Full kitchen, seats eight', 'Full kitchen', 'The Villa', 'villa', '/photos/kitchen.jpg', false],
        ['Villa bedroom', 'Villa bedroom', 'The Villa', 'villa', '/photos/room1.jpg', false],
        ['Pool and jacuzzi', 'Pool and jacuzzi', 'Grounds', 'grounds', '/photos/pool.jpg', false],
        ['Ceremony lawn, set for 120', 'Ceremony lawn', 'Open Garden', 'venues', '/photos/garden.jpg', true],
        ['Marquee Tent, long-table dinner', 'Marquee Tent', 'Marquee Tent', 'venues', '/photos/marquee.jpg', false],
        ['Glass Pangola, indoor reception', 'Glass Pangola', 'Glass Pangola', 'venues', '/photos/hall.jpg', false],
        ['Villa bedroom, mountain side', 'Mountain-side bedroom', 'The Villa', 'villa', '/photos/room2.jpg', false],
        ['Movie Night under the trees', 'Movie Night', 'Open Garden', 'events', '/photos/event.jpg', false],
    ];

    // DEMO_ACCOUNT_PASSWORD for every demo account. Never hard-code it.
    // Without it, each account gets a random password that cannot be used to sign in.
    public static function demoPassword(): string
    {
        $password = $_ENV['DEMO_ACCOUNT_PASSWORD'] ?? '';
        if ($password !== '') {
            return $password;
        }

        return bin2hex(random_bytes(24));
    }

    public static function run(): void
    {
        if (($_ENV['DEMO_ACCOUNT_PASSWORD'] ?? '') === '') {
            fwrite(STDERR, "DEMO_ACCOUNT_PASSWORD isn't set: new demo accounts get random passwords and can't sign in.\n");
        }
        self::seedUserTypes();
        self::seedStaffAccounts();
        self::seedGallery();
    }

    private static function seedUserTypes(): void
    {
        foreach (self::USER_TYPES as $name) {
            if (self::countRows('SELECT COUNT(*) FROM user_type WHERE ut_name = ?', $name) > 0) {
                continue; // already on the shared database
            }

            $insert = Db::pdo()->prepare('INSERT INTO user_type (ut_name) VALUES (?)');
            $insert->execute([$name]);
        }
    }

    private static function seedStaffAccounts(): void
    {
        foreach (self::STAFF_ACCOUNTS as [$name, $surname, $email, $phone, $role, $isActive]) {
            if (self::countRows('SELECT COUNT(*) FROM app_user WHERE u_email = ?', $email) > 0) {
                continue; // a real sign-up, or seeded before, may have changed the password since
            }

            $typeId = self::userTypeId($role);
            $insert = Db::pdo()->prepare(
                'INSERT INTO app_user (u_ut_id, u_name, u_surname, u_email, u_phone, u_password_hash, u_is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $typeId,
                $name,
                $surname,
                $email,
                $phone,
                password_hash(self::demoPassword(), PASSWORD_DEFAULT),
                (int) $isActive,
            ]);
        }
    }

    private static function seedGallery(): void
    {
        if (self::countRows('SELECT COUNT(*) FROM gallery_item', '') > 0) {
            return; // already seeded
        }

        $order = 0;
        foreach (self::GALLERY_ITEMS as [$title, $shortTitle, $spaceLabel, $category, $url, $isWide]) {
            $insert = Db::pdo()->prepare(
                'INSERT INTO gallery_item (g_title, g_short_title, g_space_label, g_category, g_url, g_is_wide, g_sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$title, $shortTitle, $spaceLabel, $category, $url, (int) $isWide, $order]);
            $order++;
        }
    }

    private static function userTypeId(string $name): int
    {
        $statement = Db::pdo()->prepare('SELECT ut_id FROM user_type WHERE ut_name = ?');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn();
    }

    // One placeholder, or none when $value is ''.
    private static function countRows(string $sql, mixed $value): int
    {
        $statement = Db::pdo()->prepare($sql);
        $statement->execute($value === '' ? [] : [$value]);

        return (int) $statement->fetchColumn();
    }
}
