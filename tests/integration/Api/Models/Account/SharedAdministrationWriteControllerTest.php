<?php

/*
 * SharedAdministrationWriteControllerTest.php
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

namespace Tests\integration\Api\Models\Account;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Account\DestroyController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Account\StoreController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Account\UpdateController
 */
final class SharedAdministrationWriteControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    public function testStoreUsesExplicitRequestedGroupAndDoesNotSwitchActiveGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $user    = $fixture['user'];

        Passport::actingAs($user);
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'user_group_id' => $fixture['requested_group']->id,
            'name'          => 'Requested checking',
            'type'          => 'asset',
            'account_role'  => 'defaultAsset',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('accounts', [
            'name'          => 'Requested checking',
            'user_id'       => $user->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testStoreWithoutUserGroupIdUsesSelectedDefaultAndKeepsV1ResponseShape(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $user    = $fixture['user'];

        Passport::actingAs($user);
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'name'         => 'Legacy checking',
            'type'         => 'asset',
            'account_role' => 'defaultAsset',
        ]);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'type'       => 'accounts',
                'attributes' => [
                    'name'         => 'Legacy checking',
                    'type'         => 'asset',
                    'account_role' => 'defaultAsset',
                ],
            ],
        ]);
        $this->assertDatabaseHas('accounts', [
            'name'          => 'Legacy checking',
            'user_id'       => $user->id,
            'user_group_id' => $fixture['active_group']->id,
        ]);
        $this->assertDatabaseMissing('accounts', [
            'name'          => 'Legacy checking',
            'user_group_id' => $fixture['requested_group']->id,
        ]);
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testStoreWithoutUserGroupIdKeepsV1ValidationErrors(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);

        Passport::actingAs($fixture['user']);
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'name' => 'Invalid legacy checking',
            'type' => 'asset',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['account_role']);
        $this->assertDatabaseMissing('accounts', [
            'name'          => 'Invalid legacy checking',
            'user_group_id' => $fixture['active_group']->id,
        ]);
        $this->assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }

    public function testReadOnlyCannotStoreInSelectedDefaultGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['user']->groupMemberships()->where('user_group_id', $fixture['active_group']->id)->delete();
        $this->createGroupMembership($fixture['user'], $fixture['active_group'], UserRoleEnum::READ_ONLY);

        Passport::actingAs($fixture['user']->refresh());
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'name'         => 'Denied selected checking',
            'type'         => 'asset',
            'account_role' => 'defaultAsset',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('accounts', [
            'name'          => 'Denied selected checking',
            'user_group_id' => $fixture['active_group']->id,
        ]);
    }

    public function testReadOnlyCannotStoreInExplicitRequestedGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);

        Passport::actingAs($fixture['user']);
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'user_group_id' => $fixture['requested_group']->id,
            'name'          => 'Denied checking',
            'type'          => 'asset',
            'account_role'  => 'defaultAsset',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('accounts', [
            'name'          => 'Denied checking',
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }

    public function testStoreDeniesConflictingQueryAndJsonUserGroupIds(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);

        Passport::actingAs($fixture['user']);
        $response = $this->postJson(route('api.v1.accounts.store', ['user_group_id' => $fixture['active_group']->id]), [
            'user_group_id' => $fixture['requested_group']->id,
            'name'          => 'Conflicting JSON checking',
            'type'          => 'asset',
            'account_role'  => 'defaultAsset',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('accounts', [
            'name' => 'Conflicting JSON checking',
        ]);
    }

    public function testStoreDeniesConflictingQueryAndFormUserGroupIds(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);

        Passport::actingAs($fixture['user']);
        $response = $this->post(route('api.v1.accounts.store', ['user_group_id' => $fixture['active_group']->id]), [
            'user_group_id' => $fixture['requested_group']->id,
            'name'          => 'Conflicting form checking',
            'type'          => 'asset',
            'account_role'  => 'defaultAsset',
        ], ['Accept' => 'application/json']);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('accounts', [
            'name' => 'Conflicting form checking',
        ]);
    }

    public function testUpdateUsesExplicitRequestedGroupForAccountOwnedByAnotherMember(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared asset');

        Passport::actingAs($fixture['user']);
        $response = $this->putJson(route('api.v1.accounts.update', ['account' => $account->id]), [
            'user_group_id' => $fixture['requested_group']->id,
            'name'          => 'Shared asset renamed',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('accounts', [
            'id'            => $account->id,
            'name'          => 'Shared asset renamed',
            'user_id'       => $owner->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }

    public function testUpdateWithoutUserGroupIdUsesSelectedDefaultAcrossMembersAndKeepsV1ResponseShape(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $user    = $fixture['user'];
        $owner   = $this->createUserInGroup($fixture['active_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['active_group'], AccountTypeEnum::ASSET, 'Selected update target');

        Passport::actingAs($user);
        $response = $this->putJson(route('api.v1.accounts.update', ['account' => $account->id]), [
            'name' => 'Selected update renamed',
        ]);

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'type'       => 'accounts',
                'id'         => (string) $account->id,
                'attributes' => [
                    'name' => 'Selected update renamed',
                    'type' => 'asset',
                ],
            ],
        ]);
        $this->assertDatabaseHas('accounts', [
            'id'            => $account->id,
            'name'          => 'Selected update renamed',
            'user_group_id' => $fixture['active_group']->id,
        ]);
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testDestroyUsesExplicitRequestedGroupForAccountOwnedByAnotherMember(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared delete target');

        Passport::actingAs($fixture['user']);
        $response = $this->deleteJson(route('api.v1.accounts.delete', [
            'account'       => $account->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertNoContent();
        $this->assertSoftDeleted('accounts', [
            'id'            => $account->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }

    public function testDestroyWithoutUserGroupIdUsesSelectedDefaultAcrossMembersAndDoesNotSwitchDefault(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $user    = $fixture['user'];
        $owner   = $this->createUserInGroup($fixture['active_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['active_group'], AccountTypeEnum::ASSET, 'Selected delete target');

        Passport::actingAs($user);
        $response = $this->deleteJson(route('api.v1.accounts.delete', ['account' => $account->id]));

        $response->assertNoContent();
        $this->assertSoftDeleted('accounts', [
            'id'            => $account->id,
            'user_group_id' => $fixture['active_group']->id,
        ]);
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testReadOnlyCannotDestroyInExplicitRequestedGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Read only delete target');

        Passport::actingAs($fixture['user']);
        $response = $this->deleteJson(route('api.v1.accounts.delete', [
            'account'       => $account->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('accounts', [
            'id'            => $account->id,
            'deleted_at'    => null,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }
}
