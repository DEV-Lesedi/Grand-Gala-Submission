<?php

declare(strict_types=1);

namespace GrandGala\Models;

use GrandGala\Utils\Db;

// API shape: id, name, surname, email, phone, role, isActive.
// The password hash is included only when a caller asks for it, so it cannot leak into JSON.
class User
{
    // Includes the password hash. Email match ignores case. null if none.
    public static function findByEmailWithHash(string $email): ?array
    {
        $statement = Db::pdo()->prepare(
            "SELECT u.u_id, u.u_name, u.u_surname, u.u_email, u.u_phone, u.u_password_hash, u.u_is_active, ut.ut_name
             FROM app_user u JOIN user_type ut ON ut.ut_id = u.u_ut_id
             WHERE LOWER(u.u_email) = LOWER(?)"
        );
        $statement->execute([trim($email)]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $user = self::toUser($row);
        $user['passwordHash'] = $row['u_password_hash'];

        return $user;
    }

    // No password hash. null if missing or switched off.
    public static function findActiveById(int $id): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT u.u_id, u.u_name, u.u_surname, u.u_email, u.u_phone, u.u_is_active, ut.ut_name
             FROM app_user u JOIN user_type ut ON ut.ut_id = u.u_ut_id
             WHERE u.u_id = ? AND u.u_is_active = 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::toUser($row);
    }

    // A missing id returns null. The plain password never comes back.
    public static function passwordHashOf(int $id): ?string
    {
        $statement = Db::pdo()->prepare('SELECT u_password_hash FROM app_user WHERE u_id = ?');
        $statement->execute([$id]);
        $hash = $statement->fetchColumn();

        return $hash === false ? null : $hash;
    }

    public static function emailTaken(string $email): bool
    {
        $statement = Db::pdo()->prepare('SELECT u_id FROM app_user WHERE LOWER(u_email) = LOWER(?)');
        $statement->execute([trim($email)]);

        return $statement->fetch() !== false;
    }

    // $role must already be a known user_type name.
    public static function create(string $name, string $surname, string $email, ?string $phone, string $passwordHash, string $role): int
    {
        $insert = Db::pdo()->prepare(
            'INSERT INTO app_user (u_ut_id, u_name, u_surname, u_email, u_phone, u_password_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([self::typeId($role), trim($name), trim($surname), trim($email), $phone, $passwordHash]);

        return (int) Db::pdo()->lastInsertId();
    }

    // Email is never changed here. It is how guests sign in.
    public static function updateProfile(int $id, string $name, string $surname, ?string $phone): void
    {
        $update = Db::pdo()->prepare('UPDATE app_user SET u_name = ?, u_surname = ?, u_phone = ? WHERE u_id = ?');
        $update->execute([trim($name), trim($surname), $phone, $id]);
    }

    // Guests only. Caller already checked the email is not taken.
    public static function updateEmail(int $id, string $email): void
    {
        $update = Db::pdo()->prepare('UPDATE app_user SET u_email = ? WHERE u_id = ?');
        $update->execute([trim($email), $id]);
    }

    public static function updatePasswordHash(int $id, string $passwordHash): void
    {
        $update = Db::pdo()->prepare('UPDATE app_user SET u_password_hash = ? WHERE u_id = ?');
        $update->execute([$passwordHash, $id]);
    }

    // Everyone except customers.
    public static function team(): array
    {
        $rows = Db::pdo()->query(
            "SELECT u.u_id, u.u_name, u.u_surname, u.u_email, u.u_phone, u.u_is_active, u.u_date_created, ut.ut_name
             FROM app_user u JOIN user_type ut ON ut.ut_id = u.u_ut_id
             WHERE ut.ut_name <> 'customer'
             ORDER BY u.u_date_created DESC, u.u_id DESC"
        )->fetchAll();

        $team = [];
        foreach ($rows as $row) {
            $person = self::toUser($row);
            $person['dateCreated'] = substr($row['u_date_created'], 0, 10);
            $team[] = $person;
        }

        return $team;
    }

    // Includes guests. Callers use this to enforce that nobody manages the super admin.
    public static function findAnyById(int $id): ?array
    {
        $statement = Db::pdo()->prepare(
            'SELECT u.u_id, u.u_name, u.u_surname, u.u_email, u.u_phone, u.u_is_active, ut.ut_name
             FROM app_user u JOIN user_type ut ON ut.ut_id = u.u_ut_id
             WHERE u.u_id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::toUser($row);
    }

    // Pass null for a field to leave it unchanged.
    public static function updateTeamMember(int $id, ?string $role, ?bool $isActive): void
    {
        $sets = [];
        $params = [];
        if ($role !== null) {
            $sets[] = 'u_ut_id = ?';
            $params[] = self::typeId($role);
        }
        if ($isActive !== null) {
            $sets[] = 'u_is_active = ?';
            $params[] = (int) $isActive;
        }
        if (count($sets) === 0) {
            return;
        }

        $params[] = $id;
        $update = Db::pdo()->prepare('UPDATE app_user SET ' . implode(', ', $sets) . ' WHERE u_id = ?');
        $update->execute($params);
    }

    private static function typeId(string $role): int
    {
        $statement = Db::pdo()->prepare('SELECT ut_id FROM user_type WHERE ut_name = ?');
        $statement->execute([$role]);

        return (int) $statement->fetchColumn();
    }

    // Never includes the password hash. Callers that need it add it after this.
    private static function toUser(array $row): array
    {
        return [
            'id' => (int) $row['u_id'],
            'name' => $row['u_name'],
            'surname' => $row['u_surname'],
            'email' => $row['u_email'],
            'phone' => $row['u_phone'],
            'role' => $row['ut_name'],
            'isActive' => $row['u_is_active'] === 1,
        ];
    }
}
