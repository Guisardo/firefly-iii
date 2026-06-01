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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $this->actingAs($this->fixture['user']);
    }
}
