<?php

/*
 * WebWriteControllerTest.php
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

namespace Tests\integration\Http\Controllers\SharedAdministration;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Http\Controllers\Transaction\CreateController
 * @covers \FireflyIII\Support\Http\SharedAdministration\ResolveSharedAdministration
 */
final class WebWriteControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    public function testTransactionCreateUsesSelectedAdministrationWhenNoQueryParameterIsPresent(): void
    {
        config()->set('view.layout', 'v1');

        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal']));

        $response->assertOk();
        $response->assertSee(sprintf('window.userGroupId = %d;', $fixture['active_group']->id), false);
        $response->assertSee(sprintf('/transactions/create/withdrawal?user_group_id=%d', $fixture['active_group']->id), false);
        $response->assertSee(sprintf('create_transaction.js?v=%d&amp;user_group_id=%d', config('firefly.build_time'), $fixture['active_group']->id), false);
    }

    public function testAccountEditHonorsExplicitRequestedAdministrationForManager(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared edit target');
        $this->actingAs($fixture['user']);

        $response = $this->get(route('accounts.edit', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertSee(sprintf('/accounts/update/%d?user_group_id=%d', $account->id, $fixture['requested_group']->id), false);
        $response->assertSee(sprintf('name="user_group_id" value="%d"', $fixture['requested_group']->id), false);
    }

    public function testAccountUpdateUsesExplicitRequestedAdministrationForManager(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared update target');
        $this->actingAs($fixture['user']);

        $response = $this->post(route('accounts.update', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]), [
            'id'            => $account->id,
            'objectType'    => 'asset',
            'name'          => 'Shared update renamed',
            'active'        => '1',
            'user_group_id' => $fixture['requested_group']->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', [
            'id'            => $account->id,
            'name'          => 'Shared update renamed',
            'user_id'       => $owner->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }

    public function testAccountUpdateReturnToEditPreservesExplicitRequestedAdministration(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared return target');
        $this->actingAs($fixture['user']);

        $response = $this->post(route('accounts.update', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]), [
            'id'             => $account->id,
            'objectType'     => 'asset',
            'name'           => 'Shared return renamed',
            'active'         => '1',
            'return_to_edit' => '1',
            'user_group_id'  => $fixture['requested_group']->id,
        ]);

        $response->assertRedirect(route('accounts.edit', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]));
    }

    public function testAccountDeleteUsesExplicitRequestedAdministrationForManager(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared delete target');
        $this->actingAs($fixture['user']);

        $response = $this->get(route('accounts.delete', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertSee(sprintf('/accounts/destroy/%d?user_group_id=%d', $account->id, $fixture['requested_group']->id), false);
        $response->assertSee(sprintf('name="user_group_id" value="%d"', $fixture['requested_group']->id), false);

        $response = $this->post(route('accounts.destroy', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]), [
            'move_account_before_delete' => '0',
            'user_group_id'              => $fixture['requested_group']->id,
        ]);

        $response->assertRedirect();
        $this->assertSoftDeleted('accounts', [
            'id'            => $account->id,
            'user_id'       => $owner->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]);
    }

    public function testReadOnlyCannotUseExplicitSharedAccountWriteRoutes(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Read only denied target');
        $this->actingAs($fixture['user']);

        $this->get(route('accounts.edit', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]))->assertForbidden();
        $this->get(route('accounts.delete', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]))->assertForbidden();
        $this->post(route('accounts.update', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]), [
            'id'            => $account->id,
            'objectType'    => 'asset',
            'name'          => 'Denied update',
            'user_group_id' => $fixture['requested_group']->id,
        ])->assertForbidden();
        $this->post(route('accounts.destroy', ['account' => $account->id, 'user_group_id' => $fixture['requested_group']->id]), [
            'move_account_before_delete' => '0',
            'user_group_id'              => $fixture['requested_group']->id,
        ])->assertForbidden();
    }

    public function testAccountEditFailsClosedWhenExplicitAdministrationDoesNotOwnAccount(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Cross group edit target');
        $this->actingAs($fixture['user']);

        $response = $this->get(route('accounts.edit', ['account' => $account->id, 'user_group_id' => $fixture['active_group']->id]));

        $response->assertNotFound();
    }

    public function testAccountEditWithoutUserGroupIdUsesSelectedDefaultAdministration(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $account = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET, 'Legacy edit target');
        $this->actingAs($fixture['user']);

        $response = $this->get(route('accounts.edit', ['account' => $account->id]));

        $response->assertOk();
        $response->assertSee(sprintf('/accounts/update/%d?user_group_id=%d', $account->id, $fixture['active_group']->id), false);
        $response->assertSee(sprintf('name="user_group_id" value="%d"', $fixture['active_group']->id), false);
    }

    public function testAccountUpdateWithoutUserGroupIdKeepsLegacyUserOwnedBehavior(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $account = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET, 'Legacy update target');
        $this->actingAs($fixture['user']);

        $response = $this->post(route('accounts.update', ['account' => $account->id]), [
            'id'         => $account->id,
            'objectType' => 'asset',
            'name'       => 'Legacy update renamed',
            'active'     => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('accounts', [
            'id'            => $account->id,
            'name'          => 'Legacy update renamed',
            'user_id'       => $fixture['user']->id,
            'user_group_id' => $fixture['active_group']->id,
        ]);
    }

    public function testTransactionCreateHonorsExplicitRequestedAdministrationForManager(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal', 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertSee(sprintf('/transactions/create/withdrawal?user_group_id=%d', $fixture['requested_group']->id), false);
    }

    public function testV1TransactionCreateExposesExplicitRequestedAdministrationForClientRequests(): void
    {
        config()->set('view.layout', 'v1');

        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal', 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertSee(sprintf('window.userGroupId = %d;', $fixture['requested_group']->id), false);
        $response->assertSee(sprintf('/transactions/create/withdrawal?user_group_id=%d', $fixture['requested_group']->id), false);
        $response->assertSee(sprintf('create_transaction.js?v=%d&amp;user_group_id=%d', config('firefly.build_time'), $fixture['requested_group']->id), false);
    }

    public function testV2TransactionCreateExposesSelectedAdministrationForClientRequests(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal']));

        $response->assertOk();
        $response->assertSee(sprintf('userGroupId: %d', $fixture['active_group']->id), false);
        $response->assertSee(sprintf('window.userGroupId = %d;', $fixture['active_group']->id), false);
    }

    public function testTransactionCreateDeniesExplicitRequestedAdministrationForReadOnlyUser(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal', 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertForbidden();
    }

    public function testTransactionCreateDeniesSelectedAdministrationForReadOnlyUser(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['user']->groupMemberships()->where('user_group_id', $fixture['active_group']->id)->delete();
        $this->createGroupMembership($fixture['user'], $fixture['active_group'], UserRoleEnum::READ_ONLY);
        $this->actingAs($fixture['user']->refresh());

        $response = $this->get(route('transactions.create', ['withdrawal']));

        $response->assertForbidden();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('view.layout', 'v2');
    }
}
