<?php

declare(strict_types=1);

namespace GrandGala\Services;

/*
 * Same checks as the account form. Returns a problem message, or '' when the input is fine.
 * The caller looks the account up; these methods never query.
 */
class AccountRules
{
    // Sign-in: one message for "no such email" and "wrong password", so nobody can find out which
    // emails have accounts. $account is Models\User::findByEmailWithHash()'s result, or null.
    public static function signInProblem(?array $account, string $password): string
    {
        if (!password_verify($password, $account['passwordHash'] ?? '')) {
            return 'That email and password don\'t match a staff account.';
        }
        if ($account['role'] === 'customer') {
            return 'This is a guest account. Guests sign in on the main site.';
        }
        if (!$account['isActive']) {
            return 'This account has been switched off. Ask an admin to turn it back on.';
        }

        return '';
    }

    // "Your details": names needed, a phone number is optional but must look like one.
    // Same regex as the frontend, digit for digit, so a number accepted in the browser is
    // never refused by the server (and the other way round).
    public static function detailsProblem(string $name, string $surname, string $phone): string
    {
        if (trim($name) === '') {
            return 'Enter your first name.';
        }
        if (trim($surname) === '') {
            return 'Enter your surname.';
        }

        $digits = preg_replace('/\D/', '', $phone);
        $trimmedPhone = trim($phone);
        if ($trimmedPhone !== '' && (preg_match('/^\+?[\d\s()-]+$/', $trimmedPhone) !== 1 || strlen($digits) < 9)) {
            return 'Enter a phone number like +27 82 555 0142.';
        }

        return '';
    }

    // "Change password": the current one must be right, the new one strong enough and different.
    public static function passwordProblem(string $currentHash, string $current, string $next, string $again): string
    {
        if (!password_verify($current, $currentHash)) {
            return 'Your current password is wrong.';
        }
        if (self::weakPassword($next)) {
            return 'Use at least 8 characters, with a letter and a number.';
        }
        if (password_verify($next, $currentHash)) {
            return 'Choose a password you haven\'t used here yet.';
        }
        if ($next !== $again) {
            return 'The new passwords don\'t match.';
        }

        return '';
    }

    // Too short, or missing a letter or a number
    public static function weakPassword(string $password): bool
    {
        return strlen($password) < 8 || preg_match('/[a-z]/i', $password) !== 1 || preg_match('/\d/', $password) !== 1;
    }

    // Guest register. Same name and password shape as a staff account.
    public static function registerProblem(string $name, string $surname, string $email, string $password): string
    {
        $details = self::detailsProblem($name, $surname, ''); // phone isn't collected at register
        if ($details !== '') {
            return $details;
        }
        if (!self::looksLikeEmail($email)) {
            return 'Enter an email address like you@email.com.';
        }
        if (self::weakPassword($password)) {
            return 'Use at least 8 characters, with a letter and a number.';
        }

        return '';
    }

    // Same email pattern the team form uses.
    public static function looksLikeEmail(string $email): bool
    {
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', trim($email)) === 1;
    }

    public static function emailTakenProblem(string $email, bool $emailTaken): string
    {
        if ($emailTaken) {
            return trim($email) . ' already has an account.';
        }

        return '';
    }
}
