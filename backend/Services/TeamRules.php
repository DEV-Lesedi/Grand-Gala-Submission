<?php

declare(strict_types=1);

namespace GrandGala\Services;

/*
 * Superadmin manages admins and staff; admin manages staff; staff have no Team page.
 * Nobody manages themselves, and nobody can grant superadmin.
 */
class TeamRules
{
    public static function canSeeTeam(array $me): bool
    {
        return $me['role'] === 'superadmin' || $me['role'] === 'admin';
    }

    // Roles $me may grant. Superadmin's list is admin, then staff.
    public static function rolesICanGive(array $me): array
    {
        if ($me['role'] === 'superadmin') {
            return ['admin', 'staff'];
        }
        if ($me['role'] === 'admin') {
            return ['staff'];
        }

        return [];
    }

    public static function canManage(array $me, array $person): bool
    {
        return $me['id'] !== $person['id'] && in_array($person['role'], self::rolesICanGive($me), true);
    }

    // Add-person checks. $emailTaken is passed in so this rule does no query of its own.
    public static function newPersonDetailsProblem(array $me, array $form, bool $emailTaken): string
    {
        $details = AccountRules::detailsProblem($form['name'], $form['surname'], $form['phone'] ?? '');
        if ($details !== '') {
            return $details;
        }
        if (!AccountRules::looksLikeEmail($form['email'])) {
            return 'Enter an email address like name@grandgala.co.za.';
        }
        $emailProblem = AccountRules::emailTakenProblem($form['email'], $emailTaken);
        if ($emailProblem !== '') {
            return $emailProblem;
        }
        if (!in_array($form['role'], self::rolesICanGive($me), true)) {
            return 'Choose a role.';
        }

        return '';
    }

    // Kept for the frontend rule's original form, where an admin also entered a starting password.
    public static function newPersonProblem(array $me, array $form, bool $emailTaken): string
    {
        $details = self::newPersonDetailsProblem($me, $form, $emailTaken);
        if ($details !== '') {
            return $details;
        }
        if (AccountRules::weakPassword($form['password'])) {
            return 'Use at least 8 characters, with a letter and a number.';
        }

        return '';
    }
}
