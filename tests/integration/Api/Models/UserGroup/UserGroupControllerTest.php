<?php

/*
 * UserGroupControllerTest.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\integration\Api\Models\UserGroup;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Repositories\UserGroup\UserGroupRepositoryInterface;
use FireflyIII\User;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\DestroyController
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\StoreController
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\UpdateController
 */
final class UserGroupControllerTest extends TestCase
{
    public function testStoreCreatesOwnerMembership(): void
    {
        $user = $this->createAuthenticatedUser();
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.store'), ['title' => 'Shared administration']);

        $response->assertOk();
        $userGroupId = $response->json('data.id');
        $this->assertDatabaseHas('user_groups', ['id' => $userGroupId, 'title' => 'Shared administration']);
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $user->id,
            'user_group_id' => $userGroupId,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testUseSwitchesDefaultAdministration(): void
    {
        $user           = $this->createAuthenticatedUser();
        $requestedGroup = UserGroup::create(['title' => 'Requested administration']);
        $this->createMembership($user, $requestedGroup, UserRoleEnum::READ_ONLY);
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.use', ['userGroup' => $requestedGroup->id]));

        $response->assertNoContent();
        self::assertSame($requestedGroup->id, $user->refresh()->user_group_id);
    }

    public function testReadOnlyMemberCanUseExplicitRequestedAdministration(): void
    {
        $user           = $this->createAuthenticatedUser();
        $requestedGroup = UserGroup::create(['title' => 'Requested explicit administration']);
        $this->createMembership($user, $requestedGroup, UserRoleEnum::READ_ONLY);
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.use', ['userGroup' => $requestedGroup->id]), [
            'user_group_id' => $requestedGroup->id,
        ]);

        $response->assertNoContent();
        self::assertSame($requestedGroup->id, $user->refresh()->user_group_id);
    }

    public function testExplicitRequestedAdministrationMustMatchRouteAdministration(): void
    {
        [$routeGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $requestedGroup       = UserGroup::create(['title' => 'Different requested administration']);
        $this->createMembership($owner, $requestedGroup, UserRoleEnum::OWNER);
        Passport::actingAs($owner);

        $response = $this->putJson(route('api.v1.user-groups.update', ['userGroup' => $routeGroup->id]), [
            'title'         => 'Should not update route group',
            'user_group_id' => $requestedGroup->id,
        ]);

        $response->assertUnauthorized();
        self::assertNotSame('Should not update route group', $routeGroup->refresh()->title);
    }

    public function testFullMemberCanAddNonOwnerMember(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        $target                 = $this->newUser('target-full-add@example.test');
        Passport::actingAs($fullUser);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::READ_ONLY),
        ]);
    }

    public function testReadOnlyMemberCannotChangeMembers(): void
    {
        [$userGroup, $readOnlyUser] = $this->groupWithActingUser(UserRoleEnum::READ_ONLY);
        $target                     = $this->newUser('target-read-only-denied@example.test');
        Passport::actingAs($readOnlyUser);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $userGroup->id,
        ]);
    }

    public function testReadOnlyMemberCannotUpdateGroupMetadata(): void
    {
        [$userGroup, $readOnlyUser] = $this->groupWithActingUser(UserRoleEnum::READ_ONLY);
        Passport::actingAs($readOnlyUser);

        $response = $this->putJson(route('api.v1.user-groups.update', ['userGroup' => $userGroup->id]), [
            'title' => 'Denied title update',
        ]);

        $response->assertUnauthorized();
        self::assertNotSame('Denied title update', $userGroup->refresh()->title);
    }

    public function testReadOnlyMemberCannotUpdateGroupCurrency(): void
    {
        [$userGroup, $readOnlyUser] = $this->groupWithActingUser(UserRoleEnum::READ_ONLY);
        $currency                   = TransactionCurrency::whereCode('EUR')->firstOrFail();
        Passport::actingAs($readOnlyUser);

        $response = $this->putJson(route('api.v1.user-groups.update', ['userGroup' => $userGroup->id]), [
            'title'               => $userGroup->title,
            'primary_currency_id' => $currency->id,
        ]);

        $response->assertUnauthorized();
    }

    public function testFullMemberCannotDestroyGroup(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        Passport::actingAs($fullUser);

        $response = $this->deleteJson(route('api.v1.user-groups.destroy', ['userGroup' => $userGroup->id]));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('user_groups', ['id' => $userGroup->id]);
    }

    public function testBlockedOwnerCannotUpdateGroupMetadata(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $owner->blocked      = true;
        $owner->save();
        Passport::actingAs($owner->refresh());

        $response = $this->putJson(route('api.v1.user-groups.update', ['userGroup' => $userGroup->id]), [
            'title' => 'Blocked title update',
        ]);

        $response->assertUnauthorized();
        self::assertNotSame('Blocked title update', $userGroup->refresh()->title);
    }

    public function testBlockedFullMemberCannotChangeMembers(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        $target                 = $this->newUser('target-blocked-full-denied@example.test');
        $fullUser->blocked      = true;
        $fullUser->save();
        Passport::actingAs($fullUser->refresh());

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $userGroup->id,
        ]);
    }

    public function testBlockedOwnerCannotDestroyGroup(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $owner->blocked      = true;
        $owner->save();
        Passport::actingAs($owner->refresh());

        $response = $this->deleteJson(route('api.v1.user-groups.destroy', ['userGroup' => $userGroup->id]));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('user_groups', ['id' => $userGroup->id]);
    }

    public function testBlockedMemberCannotSwitchDefaultAdministration(): void
    {
        $user           = $this->createAuthenticatedUser();
        $initialGroupId = $user->user_group_id;
        $requestedGroup = UserGroup::create(['title' => 'Blocked switch target']);
        $this->createMembership($user, $requestedGroup, UserRoleEnum::READ_ONLY);
        $user->blocked  = true;
        $user->save();
        Passport::actingAs($user->refresh());

        $response = $this->postJson(route('api.v1.user-groups.use', ['userGroup' => $requestedGroup->id]));

        $response->assertUnauthorized();
        self::assertSame($initialGroupId, $user->refresh()->user_group_id);
    }

    public function testBlockedUserCannotCreateGroup(): void
    {
        $user          = $this->createAuthenticatedUser();
        $user->blocked = true;
        $user->save();
        Passport::actingAs($user->refresh());

        $response = $this->postJson(route('api.v1.user-groups.store'), ['title' => 'Blocked administration']);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('user_groups', ['title' => 'Blocked administration']);
    }

    public function testMemberCannotChangeMembershipsInAnotherGroup(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        $otherGroup             = UserGroup::create(['title' => 'Other administration']);
        $target                 = $this->newUser('target-cross-group-denied@example.test');
        Passport::actingAs($fullUser);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $otherGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $otherGroup->id,
        ]);
        $this->assertDatabaseHas('user_groups', ['id' => $userGroup->id]);
    }

    public function testFullMemberCannotGrantOwner(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        $target                 = $this->newUser('target-full-owner-denied@example.test');
        Passport::actingAs($fullUser);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::OWNER->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testOwnerCanGrantOwner(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $target             = $this->newUser('target-owner-grant@example.test');
        Passport::actingAs($owner);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $target->id,
            'roles' => [UserRoleEnum::OWNER->value],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $target->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testFullMemberCannotRevokeOwner(): void
    {
        [$userGroup, $fullUser] = $this->groupWithActingUser(UserRoleEnum::FULL);
        $targetOwner            = $this->newUser('target-full-revoke-owner-denied@example.test', $userGroup);
        $this->createMembership($targetOwner, $userGroup, UserRoleEnum::OWNER);
        Passport::actingAs($fullUser);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $targetOwner->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $targetOwner->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testOwnerCanRevokeOwnerWhenAnotherOwnerRemains(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $targetOwner         = $this->newUser('target-owner-revoke@example.test', $userGroup);
        $this->createMembership($targetOwner, $userGroup, UserRoleEnum::OWNER);
        Passport::actingAs($owner);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $targetOwner->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $targetOwner->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $targetOwner->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::READ_ONLY),
        ]);
    }

    public function testOwnerCannotRevokeLastOwner(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $fullUser            = $this->newUser('full-last-owner-guard@example.test', $userGroup);
        $this->createMembership($fullUser, $userGroup, UserRoleEnum::FULL);
        Passport::actingAs($owner);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $owner->id,
            'roles' => [UserRoleEnum::FULL->value],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $owner->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testRepositoryRefusesStaleLastOwnerRemoval(): void
    {
        [$userGroup, $owner] = $this->groupWithActingUser(UserRoleEnum::OWNER);
        $otherOwner          = $this->newUser('other-owner-race@example.test', $userGroup);
        $this->createMembership($otherOwner, $userGroup, UserRoleEnum::OWNER);
        $otherOwner->groupMemberships()
            ->where('user_group_id', $userGroup->id)
            ->where('user_role_id', $this->roleId(UserRoleEnum::OWNER))
            ->delete()
        ;

        /** @var UserGroupRepositoryInterface $repository */
        $repository = app(UserGroupRepositoryInterface::class);
        $repository->setUser($owner);

        try {
            $repository->updateMembership($userGroup, [
                'id'    => $owner->id,
                'roles' => [UserRoleEnum::FULL->value],
            ]);
            self::fail('Expected last-owner removal to be refused.');
        } catch (FireflyException) {
            $this->assertDatabaseHas('group_memberships', [
                'user_id'       => $owner->id,
                'user_group_id' => $userGroup->id,
                'user_role_id'  => $this->roleId(UserRoleEnum::OWNER),
            ]);
        }
    }

    public function testOwnerCanDestroyGroupAndFallbackUsesUserGroupId(): void
    {
        $user         = $this->createAuthenticatedUser();
        $initialGroup = $user->userGroup;
        $otherGroup   = UserGroup::create(['title' => 'Fallback administration']);
        $otherOwner   = $this->newUser('other-owner@example.test', $otherGroup);
        $this->createMembership($otherOwner, $otherGroup, UserRoleEnum::OWNER);
        $this->createMembership($user, $otherGroup, UserRoleEnum::OWNER);
        Passport::actingAs($user);

        $response = $this->deleteJson(route('api.v1.user-groups.destroy', ['userGroup' => $initialGroup->id]));

        $response->assertNoContent();
        $this->assertDatabaseMissing('user_groups', ['id' => $initialGroup->id]);
        self::assertSame($otherGroup->id, $user->refresh()->user_group_id);
    }

    private function groupWithActingUser(UserRoleEnum $role): array
    {
        $userGroup = UserGroup::create(['title' => sprintf('Group for %s', $role->value)]);
        $owner     = $this->newUser(sprintf('owner-%s@example.test', $role->value), $userGroup);
        $this->createMembership($owner, $userGroup, UserRoleEnum::OWNER);

        if (UserRoleEnum::OWNER === $role) {
            return [$userGroup, $owner];
        }

        $user = $this->newUser(sprintf('acting-%s@example.test', $role->value), $userGroup);
        $this->createMembership($user, $userGroup, $role);

        return [$userGroup, $user];
    }

    private function newUser(string $email, ?UserGroup $userGroup = null): User
    {
        return User::create([
            'email'         => $email,
            'password'      => 'password',
            'user_group_id' => $userGroup?->id,
        ]);
    }

    private function createMembership(User $user, UserGroup $userGroup, UserRoleEnum $role): GroupMembership
    {
        return GroupMembership::create([
            'user_id'       => $user->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->roleId($role),
        ]);
    }

    private function roleId(UserRoleEnum $role): int
    {
        return UserRole::query()->where('title', $role->value)->firstOrFail()->id;
    }
}
