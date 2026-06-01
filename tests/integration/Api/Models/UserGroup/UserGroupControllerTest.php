<?php

/*
 * UserGroupControllerTest.php
 * Copyright (c) 2026 james@firefly-iii.org.
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace Tests\integration\Api\Models\UserGroup;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\DestroyController
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\StoreController
 * @covers \FireflyIII\Api\V1\Controllers\Models\UserGroup\UpdateController
 */
final class UserGroupControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    public function testStoreCreatesAdministrationWithOwnerMembership(): void
    {
        $user = $this->createAuthenticatedUser();
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.store'), ['title' => 'New shared administration']);

        $response->assertOk();
        $response->assertJson(['data' => ['attributes' => ['title' => 'New shared administration']]]);

        $userGroupId = (int) $response->json('data.id');
        $this->assertDatabaseHas('user_groups', ['id' => $userGroupId, 'title' => 'New shared administration']);
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $user->id,
            'user_group_id' => $userGroupId,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testSwitchUsesRequestedAdministrationNotCurrentDefault(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        /** @var User $user */
        $user    = $fixture['user'];
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.use', ['userGroup' => $fixture['requested_group']->id]));

        $response->assertOk();
        $this->assertSame($fixture['requested_group']->id, $user->refresh()->user_group_id);
    }

    public function testSwitchDeniesNonMember(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::OWNER);
        /** @var User $user */
        $user    = $fixture['user'];
        Passport::actingAs($user);

        $response = $this->postJson(route('api.v1.user-groups.use', ['userGroup' => $fixture['unrelated_group']->id]));

        $response->assertNotFound();
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testReadOnlyCannotUpdateAdministration(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $title   = $fixture['requested_group']->title;
        Passport::actingAs($fixture['user']);

        $response = $this->putJson(route('api.v1.user-groups.update', ['userGroup' => $fixture['requested_group']->id]), [
            'title' => 'Denied title',
        ]);

        $response->assertUnauthorized();
        $this->assertSame($title, $fixture['requested_group']->refresh()->title);
    }

    public function testFullUserCanUpdateAndRemoveMemberRoles(): void
    {
        $userGroup = UserGroup::create(['title' => 'Membership administration']);
        $owner     = $this->createUserInGroup($userGroup, UserRoleEnum::OWNER);
        $full      = $this->createUserInGroup($userGroup, UserRoleEnum::FULL);
        $member    = $this->createUserInGroup($userGroup, UserRoleEnum::MANAGE_TRANSACTIONS);
        Passport::actingAs($full);

        $response  = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $member->id,
            'roles' => [UserRoleEnum::READ_ONLY->value],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::MANAGE_TRANSACTIONS),
        ]);
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::READ_ONLY),
        ]);

        $response = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'email' => $member->email,
            'roles' => [],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
        ]);
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $owner->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testReadOnlyCannotUpdateMemberRoles(): void
    {
        $userGroup = UserGroup::create(['title' => 'Read-only membership administration']);
        $readOnly  = $this->createUserInGroup($userGroup, UserRoleEnum::READ_ONLY);
        $member    = $this->createUserInGroup($userGroup, UserRoleEnum::MANAGE_TRANSACTIONS);
        Passport::actingAs($readOnly);

        $response  = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $member->id,
            'roles' => [UserRoleEnum::FULL->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseHas('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::MANAGE_TRANSACTIONS),
        ]);
    }

    public function testFullUserCannotGrantOwnerRole(): void
    {
        $userGroup = UserGroup::create(['title' => 'Owner role administration']);
        $this->createUserInGroup($userGroup, UserRoleEnum::OWNER);
        $full      = $this->createUserInGroup($userGroup, UserRoleEnum::FULL);
        $member    = $this->createUserInGroup($userGroup, UserRoleEnum::READ_ONLY);
        Passport::actingAs($full);

        $response  = $this->putJson(route('api.v1.user-groups.updateMembership', ['userGroup' => $userGroup->id]), [
            'id'    => $member->id,
            'roles' => [UserRoleEnum::OWNER->value],
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $this->userRoleId(UserRoleEnum::OWNER),
        ]);
    }

    public function testFullUserCannotDestroyAdministration(): void
    {
        $userGroup = UserGroup::create(['title' => 'Destroy denied administration']);
        $this->createUserInGroup($userGroup, UserRoleEnum::OWNER);
        $full      = $this->createUserInGroup($userGroup, UserRoleEnum::FULL);
        Passport::actingAs($full);

        $response  = $this->deleteJson(route('api.v1.user-groups.destroy', ['userGroup' => $userGroup->id]));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('user_groups', ['id' => $userGroup->id]);
    }

    public function testOwnerDestroysAdministrationAndReplacementUsesUserGroupId(): void
    {
        $userGroup        = UserGroup::create(['title' => 'Deleted administration']);
        $replacementGroup = UserGroup::create(['title' => 'Replacement administration']);
        $owner            = $this->createUserInGroup($userGroup, UserRoleEnum::OWNER);
        $member           = $this->createUserInGroup($userGroup, UserRoleEnum::READ_ONLY);
        $this->createGroupMembership($member, $replacementGroup, UserRoleEnum::READ_ONLY);
        Passport::actingAs($owner);

        $response         = $this->deleteJson(route('api.v1.user-groups.destroy', ['userGroup' => $userGroup->id]));

        $response->assertStatus(204);
        $this->assertDatabaseMissing('user_groups', ['id' => $userGroup->id]);
        $this->assertSame($replacementGroup->id, $member->refresh()->user_group_id);
        $this->assertDatabaseMissing('group_memberships', [
            'user_id'       => $member->id,
            'user_group_id' => $userGroup->id,
        ]);
    }

    private function userRoleId(UserRoleEnum $role): int
    {
        return (int) UserRole::query()->where('title', $role->value)->value('id');
    }
}
