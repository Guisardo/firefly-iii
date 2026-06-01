<?php

/*
 * SharedAdministrationListControllerTest.php
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

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Account\ListController
 */
final class SharedAdministrationListControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    private TransactionGroup $transactionGroup;
    private User $readOnlyUser;

    public function testReadOnlyCanListTransactionsForExplicitRequestedGroupAccountOwnedByAnotherMember(): void
    {
        $account = $this->transactionGroup->transactionJournals->first()->transactions->first()->account;

        Passport::actingAs($this->readOnlyUser);
        $response = $this->getJson(route('api.v1.accounts.transactions', [
            'account'       => $account->id,
            'user_group_id' => $account->user_group_id,
        ]));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        $response->assertJsonPath('data.0.id', (string) $this->transactionGroup->id);
    }

    public function testReadOnlyCanListTransactionsForSelectedDefaultGroupAccountOwnedByAnotherMember(): void
    {
        $owner            = $this->createUserInGroup($this->readOnlyUser->userGroup, UserRoleEnum::OWNER);
        $transactionGroup = $this->createWithdrawalInGroup($owner, $this->readOnlyUser->userGroup);
        $account          = $transactionGroup->transactionJournals->first()->transactions->first()->account;

        Passport::actingAs($this->readOnlyUser);
        $response = $this->getJson(route('api.v1.accounts.transactions', [
            'account' => $account->id,
        ]));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        $response->assertJsonPath('data.0.id', (string) $transactionGroup->id);
    }

    public function testParentAccountMustMatchExplicitRequestedGroup(): void
    {
        $account = $this->transactionGroup->transactionJournals->first()->transactions->first()->account;

        Passport::actingAs($this->readOnlyUser);
        $response = $this->getJson(route('api.v1.accounts.transactions', [
            'account'       => $account->id,
            'user_group_id' => $this->readOnlyUser->user_group_id,
        ]));

        $response->assertNotFound();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $fixture                = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $owner                  = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $this->readOnlyUser     = $fixture['user'];
        $this->transactionGroup = $this->createWithdrawalInGroup($owner, $fixture['requested_group']);
    }
}
