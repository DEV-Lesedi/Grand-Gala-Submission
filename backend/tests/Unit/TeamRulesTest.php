<?php

declare(strict_types=1);

namespace GrandGala\Tests\Unit;

use GrandGala\Services\TeamRules;
use PHPUnit\Framework\TestCase;

// Same cases as teamRules.js: Team access, role grants, and no self or super-admin management.
class TeamRulesTest extends TestCase
{
    private function person(int $id, string $role): array
    {
        return ['id' => $id, 'role' => $role];
    }

    public function testOnlySuperadminAndAdminSeeTheTeamPage(): void
    {
        self::assertTrue(TeamRules::canSeeTeam($this->person(1, 'superadmin')));
        self::assertTrue(TeamRules::canSeeTeam($this->person(2, 'admin')));
        self::assertFalse(TeamRules::canSeeTeam($this->person(3, 'staff')));
        self::assertFalse(TeamRules::canSeeTeam($this->person(4, 'customer')));
    }

    public function testSuperadminCanGiveAdminOrStaff(): void
    {
        self::assertSame(['admin', 'staff'], TeamRules::rolesICanGive($this->person(1, 'superadmin')));
    }

    public function testAdminCanOnlyGiveStaff(): void
    {
        self::assertSame(['staff'], TeamRules::rolesICanGive($this->person(2, 'admin')));
    }

    public function testStaffCanGiveNoRoles(): void
    {
        self::assertSame([], TeamRules::rolesICanGive($this->person(3, 'staff')));
    }

    public function testNobodyManagesThemselves(): void
    {
        $superadmin = $this->person(1, 'superadmin');

        // Same id: a superadmin still cannot manage an admin with their own id.
        self::assertFalse(TeamRules::canManage($superadmin, $this->person(1, 'admin')));
    }

    public function testNobodyManagesTheSuperAdmin(): void
    {
        $admin = $this->person(2, 'admin');
        $superadmin = $this->person(1, 'superadmin');

        // rolesICanGive(admin) excludes superadmin, so that case needs no extra branch.
        self::assertFalse(TeamRules::canManage($admin, $superadmin));
        self::assertFalse(TeamRules::canManage($this->person(99, 'superadmin'), $superadmin));
    }

    public function testAdminCanManageStaffButNotAnotherAdmin(): void
    {
        $admin = $this->person(2, 'admin');

        self::assertTrue(TeamRules::canManage($admin, $this->person(3, 'staff')));
        self::assertFalse(TeamRules::canManage($admin, $this->person(5, 'admin')));
    }

    public function testNewPersonNeedsAUniqueEmailAndAGivableRole(): void
    {
        $admin = $this->person(2, 'admin');
        $form = ['name' => 'Sipho', 'surname' => 'Ndlovu', 'phone' => '', 'email' => 'sipho@grandgala.co.za', 'role' => 'staff', 'password' => 'staff-2026'];

        self::assertSame('', TeamRules::newPersonProblem($admin, $form, emailTaken: false));
        self::assertStringContainsString('already has an account.', TeamRules::newPersonProblem($admin, $form, emailTaken: true));

        $adminTryingToAddAnAdmin = array_merge($form, ['role' => 'admin']);
        self::assertSame('Choose a role.', TeamRules::newPersonProblem($admin, $adminTryingToAddAnAdmin, emailTaken: false));
    }
}
