<?php

/*
 * WebReadControllerTest.php
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
use Illuminate\Pagination\LengthAwarePaginator;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Http\Controllers\Account\IndexController
 * @covers \FireflyIII\Http\Controllers\Transaction\IndexController
 * @covers \FireflyIII\Support\Http\SharedAdministration\ResolveSharedAdministration
 */
final class WebReadControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private array $fixture;

    public function testAccountIndexUsesSelectedAdministrationWhenNoQueryParameterIsPresent(): void
    {
        $activeCreator    = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $activeAccount    = $this->createAccountInGroup($activeCreator, $this->fixture['active_group'], AccountTypeEnum::ASSET, 'Active scoped asset');
        $requestedAccount = $this->createAccountInGroup($this->fixture['user'], $this->fixture['requested_group'], AccountTypeEnum::ASSET, 'Requested scoped asset');

        $response         = $this->get(route('accounts.index', ['asset']));

        $response->assertOk();
        $response->assertViewHas('accounts', static function (LengthAwarePaginator $accounts) use ($activeAccount, $requestedAccount): bool {
            $ids = $accounts->getCollection()->pluck('id')->all();

            return in_array($activeAccount->id, $ids, true) && !in_array($requestedAccount->id, $ids, true);
        });
        $response->assertSee(sprintf('%s?user_group_id=%d', route('accounts.create', ['asset']), $this->fixture['active_group']->id), false);
    }

    public function testAccountIndexHonorsExplicitRequestedAdministration(): void
    {
        $requestedAccount = $this->createAccountInGroup($this->fixture['user'], $this->fixture['requested_group'], AccountTypeEnum::ASSET, 'Requested scoped asset');
        $activeAccount    = $this->createAccountInGroup($this->fixture['user'], $this->fixture['active_group'], AccountTypeEnum::ASSET, 'Active scoped asset');

        $response         = $this->get(route('accounts.index', ['asset', 'user_group_id' => $this->fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertViewHas('accounts', static function (LengthAwarePaginator $accounts) use ($requestedAccount, $activeAccount): bool {
            $ids = $accounts->getCollection()->pluck('id')->all();

            return in_array($requestedAccount->id, $ids, true) && !in_array($activeAccount->id, $ids, true);
        });
    }

    public function testTransactionIndexUsesSelectedAdministrationWhenNoQueryParameterIsPresent(): void
    {
        $activeCreator  = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $activeGroup    = $this->createWithdrawalInGroup($activeCreator, $this->fixture['active_group']);
        $requestedGroup = $this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['requested_group']);

        $response       = $this->get(route('transactions.index', ['withdrawal', '2026-05-01', '2026-06-30']));

        $response->assertOk();
        $response->assertViewHas('groups', static function (LengthAwarePaginator $groups) use ($activeGroup, $requestedGroup): bool {
            $ids = $groups->getCollection()->pluck('id')->all();

            return in_array($activeGroup->id, $ids, true) && !in_array($requestedGroup->id, $ids, true);
        });
        $response->assertSee(sprintf('%s?user_group_id=%d', route('transactions.create', ['withdrawal']), $this->fixture['active_group']->id), false);
        $response->assertSee(sprintf('%s?user_group_id=%d', route('chart.transactions.categories', ['withdrawal', '2026-05-01', '2026-06-30']), $this->fixture['active_group']->id), false);
    }

    public function testTransactionAllIndexUsesSelectedAdministrationWhenNoQueryParameterIsPresent(): void
    {
        $activeCreator  = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $activeGroup    = $this->createWithdrawalInGroup($activeCreator, $this->fixture['active_group']);
        $requestedGroup = $this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['requested_group']);

        $response       = $this->get(route('transactions.index.all', ['all']));

        $response->assertOk();
        $response->assertViewHas('groups', static function (LengthAwarePaginator $groups) use ($activeGroup, $requestedGroup): bool {
            $ids = $groups->getCollection()->pluck('id')->all();

            return in_array($activeGroup->id, $ids, true) && !in_array($requestedGroup->id, $ids, true);
        });
    }

    public function testAccountShowHonorsExplicitRequestedAdministration(): void
    {
        $owner   = $this->createUserInGroup($this->fixture['requested_group'], UserRoleEnum::OWNER);
        $account = $this->createAccountInGroup($owner, $this->fixture['requested_group'], AccountTypeEnum::ASSET, 'Requested shared asset');

        $response = $this->get(sprintf('%s?user_group_id=%d', route('accounts.show', ['account' => $account->id]), $this->fixture['requested_group']->id));

        $response->assertOk();
        $response->assertViewHas('account', static fn ($viewAccount): bool => $viewAccount->id === $account->id);
        $response->assertSee(sprintf('/accounts/edit/%d?user_group_id=%d', $account->id, $this->fixture['requested_group']->id), false);
        $response->assertSee(sprintf('/accounts/delete/%d?user_group_id=%d', $account->id, $this->fixture['requested_group']->id), false);
    }

    public function testTransactionIndexHonorsExplicitRequestedAdministration(): void
    {
        $requestedGroup = $this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['requested_group']);
        $activeGroup    = $this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['active_group']);

        $response       = $this->get(route('transactions.index.all', ['all', 'user_group_id' => $this->fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertViewHas('groups', static function (LengthAwarePaginator $groups) use ($requestedGroup, $activeGroup): bool {
            $ids = $groups->getCollection()->pluck('id')->all();

            return in_array($requestedGroup->id, $ids, true) && !in_array($activeGroup->id, $ids, true);
        });
    }

    public function testWebReadDeniesUnrelatedAdministration(): void
    {
        $response = $this->get(route('accounts.index', ['asset', 'user_group_id' => $this->fixture['unrelated_group']->id]));

        $response->assertForbidden();
    }

    public function testWebReadDeniesSelectedAdministrationWithoutMembership(): void
    {
        $this->fixture['user']->user_group_id = $this->fixture['unrelated_group']->id;
        $this->fixture['user']->save();

        $response = $this->get(route('accounts.index', ['asset']));

        $response->assertForbidden();
    }

    public function testAdministrationsIndexRendersV2PageStateScript(): void
    {
        $response = $this->get(route('administrations.index'));

        $response->assertOk();
        $response->assertSee('window.fireflyPageState', false);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('view.layout', 'v1');

        $this->fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $this->actingAs($this->fixture['user']);
    }
}
