<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\PasswordReset;
use GrandGala\Models\User;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

/*
 * Register, sign-in, resets and invites. Controllers only read the request; the rules live here.
 * One message either way, so callers cannot learn which emails have accounts.
 */
class Accounts
{
    // How long a reset link works for
    private const RESET_TTL_MINUTES = 60;

    // An invite lasts longer than a reset: people do not always open it straight away.
    private const INVITE_TTL_HOURS = 72;

    // Placeholder until they choose a password. password_verify() never matches it.
    private const NO_PASSWORD = 'This account needs an invite link before it has a password.';

    public static function register(string $fullName, string $email, string $password): array
    {
        [$name, $surname] = self::splitFullName($fullName);
        $problem = AccountRules::registerProblem($name, $surname, $email, $password);
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }
        $emailProblem = AccountRules::emailTakenProblem($email, User::emailTaken($email));
        if ($emailProblem !== '') {
            throw new HttpError(422, $emailProblem);
        }

        $userId = User::create($name, $surname, $email, null, self::hashPassword($password), 'customer');

        return ['token' => Sessions::issue($userId), 'user' => User::findActiveById($userId)];
    }

    public static function signInGuest(string $email, string $password): array
    {
        self::requireSignInFields($email, $password);
        $account = User::findByEmailWithHash($email);

        // One message either way, so nobody can tell which emails have accounts
        if ($account === null || !password_verify($password, $account['passwordHash'])) {
            throw new HttpError(422, 'That email and password don\'t match an account.');
        }
        if (!$account['isActive']) {
            throw new HttpError(403, 'This account has been switched off.');
        }
        // Guest sign-in is for guests only; staff sign in on the staff page (agreed 12 Oct 2026).
        // Checked after the password, so it never tells a stranger which emails belong to staff.
        if ($account['role'] !== 'customer') {
            throw new HttpError(403, 'Staff sign in on the staff sign-in page.');
        }

        return ['token' => Sessions::issue($account['id']), 'user' => User::findActiveById($account['id'])];
    }

    public static function signInStaff(string $email, string $password): array
    {
        self::requireSignInFields($email, $password);
        $account = User::findByEmailWithHash($email);
        $problem = AccountRules::signInProblem($account, $password);
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }

        return ['token' => Sessions::issue($account['id']), 'user' => User::findActiveById($account['id'])];
    }

    public static function signOut(?string $token): array
    {
        if ($token !== null) {
            Sessions::revoke($token);
        }

        return ['ok' => true];
    }

    // Forgot password: always answers the same way, whether or not the email has an account,
    // so this can't be used to find out who has signed up.
    public static function requestReset(string $email): array
    {
        $account = User::findByEmailWithHash($email);
        if ($account !== null && $account['isActive']) {
            self::sendResetLink($account['id'], $account['email'], $account['role'], [], false);
        }

        return ['ok' => true];
    }

    public static function resetWithToken(string $rawToken, string $password): array
    {
        if ($rawToken === '') {
            throw new HttpError(422, 'This reset link is missing its token.');
        }
        if (AccountRules::weakPassword($password)) {
            throw new HttpError(422, 'Use at least 8 characters, with a letter and a number.');
        }

        $passwordHash = self::hashPassword($password);
        Db::transaction(function () use ($rawToken, $passwordHash) {
            $reset = PasswordReset::findUsable(hash('sha256', $rawToken));
            if ($reset === null) {
                throw new HttpError(422, 'This reset link has expired or was already used.');
            }

            User::updatePasswordHash($reset['userId'], $passwordHash);
            PasswordReset::markUsed($reset['id']);
            // A password reset ends every open session - including whoever asked for the reset,
            // if their old session is still sitting in another tab.
            self::endSessions($reset['userId']);
        });

        return ['ok' => true];
    }

    public static function team(array $me): array
    {
        if (!TeamRules::canSeeTeam($me)) {
            throw new HttpError(403, 'You don\'t have access to this');
        }

        return User::team();
    }

    public static function invite(array $me, array $form): array
    {
        if (!TeamRules::canSeeTeam($me)) {
            throw new HttpError(403, 'You don\'t have access to this');
        }

        $problem = TeamRules::newPersonDetailsProblem($me, $form, User::emailTaken($form['email']));
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }

        $userId = User::create(
            $form['name'],
            $form['surname'],
            $form['email'],
            $form['phone'] === '' ? null : $form['phone'],
            self::NO_PASSWORD,
            $form['role']
        );
        self::sendResetLink($userId, $form['email'], $form['role'], $me, true);

        return User::findActiveById($userId);
    }

    public static function updateTeamMember(
        array $me,
        string $personId,
        mixed $role,
        ?bool $isActive
    ): array {
        $person = self::person($personId);
        if (!TeamRules::canManage($me, $person)) {
            throw new HttpError(403, 'You don\'t have access to this');
        }
        if ($role !== null && (!is_string($role) || !in_array($role, TeamRules::rolesICanGive($me), true))) {
            throw new HttpError(422, 'Choose a role.');
        }

        User::updateTeamMember($person['id'], $role, $isActive);
        // Switching off or changing role ends sessions, so an old device loses that access.
        if ($isActive === false || $role !== null) {
            self::endSessions($person['id']);
        }

        return User::findActiveById($person['id']) ?? User::findAnyById($person['id']);
    }

    public static function sendTeamReset(array $me, string $personId): array
    {
        $person = self::person($personId);
        if (!TeamRules::canManage($me, $person)) {
            throw new HttpError(403, 'You don\'t have access to this');
        }

        self::sendResetLink($person['id'], $person['email'], $person['role'], $me, false);

        return ['ok' => true];
    }

    public static function updateStaffDetails(array $me, string $name, string $surname, string $phone): array
    {
        $problem = AccountRules::detailsProblem($name, $surname, $phone);
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }

        User::updateProfile($me['id'], $name, $surname, $phone === '' ? null : $phone);

        return User::findActiveById($me['id']);
    }

    public static function changePassword(
        array $me,
        string $current,
        string $next,
        string $again,
        ?string $currentToken
    ): array {
        $currentHash = User::passwordHashOf($me['id']);
        if ($currentHash === null) {
            throw new HttpError(404, 'Account not found');
        }

        $problem = AccountRules::passwordProblem($currentHash, $current, $next, $again);
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }

        User::updatePasswordHash($me['id'], self::hashPassword($next));
        // A password change signs out other devices, not the one making the change.
        self::endSessions($me['id'], $currentToken);

        return ['ok' => true];
    }

    public static function updateGuestProfile(
        array $me,
        string $fullName,
        string $email,
        string $phone
    ): array {
        [$name, $surname] = self::splitFullName($fullName);
        $problem = AccountRules::detailsProblem($name, $surname, $phone);
        if ($problem !== '') {
            throw new HttpError(422, $problem);
        }
        if (!AccountRules::looksLikeEmail($email)) {
            throw new HttpError(422, 'Enter an email address like you@email.com.');
        }

        $emailChanged = strtolower(trim($email)) !== strtolower($me['email']);
        // Staff keep their work email. They change their details on My account.
        if ($emailChanged && $me['role'] !== 'customer') {
            throw new HttpError(422, 'Staff can\'t change their email here.');
        }
        $emailProblem = AccountRules::emailTakenProblem($email, $emailChanged && User::emailTaken($email));
        if ($emailProblem !== '') {
            throw new HttpError(422, $emailProblem);
        }

        User::updateProfile($me['id'], $name, $surname, $phone === '' ? null : $phone);
        // updateProfile() never touches email, so it is saved separately.
        User::updateEmail($me['id'], $email);

        return User::findActiveById($me['id']);
    }

    // One link: an invite, or a reset. Only a hash is stored; the token lives only in the email.
    private static function sendResetLink(
        int $userId,
        string $email,
        string $role,
        array $invitedBy,
        bool $isInvite
    ): void {
        $rawToken = bin2hex(random_bytes(32));
        $lifetime = '+' . self::RESET_TTL_MINUTES . ' minutes';
        if ($isInvite) {
            $lifetime = '+' . self::INVITE_TTL_HOURS . ' hours';
        }
        PasswordReset::create(
            $userId,
            hash('sha256', $rawToken),
            date('Y-m-d H:i:s', strtotime($lifetime))
        );

        $url = rtrim($_ENV['FRONTEND_URL'] ?? '', '/') . '/reset-password?token=' . $rawToken;
        if ($isInvite) {
            Mailer::send('staff_invite', $email, [
                'inviteUrl' => $url,
                'invitedBy' => $invitedBy['name'] . ' ' . $invitedBy['surname'],
                'role' => $role,
                'expiresInHours' => self::INVITE_TTL_HOURS,
            ]);

            return;
        }

        Mailer::send('password_reset', $email, [
            'resetUrl' => $url,
            'expiresInMinutes' => self::RESET_TTL_MINUTES,
        ]);
    }

    private static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    private static function endSessions(int $userId, ?string $keepToken = null): void
    {
        if ($keepToken === null) {
            Sessions::revokeAllForUser($userId);

            return;
        }

        Sessions::revokeAllForUserExcept($userId, $keepToken);
    }

    // Splits a full name. A single word leaves the surname empty so register asks for it.
    private static function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private static function requireSignInFields(string $email, string $password): void
    {
        if (trim($email) === '') {
            throw new HttpError(422, 'Enter your email address.');
        }
        if ($password === '') {
            throw new HttpError(422, 'Enter your password.');
        }
    }

    private static function person(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new HttpError(404, 'Account not found');
        }

        $person = User::findAnyById((int) $id);
        if ($person === null) {
            throw new HttpError(404, 'Account not found');
        }

        return $person;
    }
}
