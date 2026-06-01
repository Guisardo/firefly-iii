<?php

/*
 * AccountControllerTest.php
 * Copyright (c) 2025 james@firefly-iii.org
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
use FireflyIII\Models\Account;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Account\ShowController
 */
final class ShowControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMultiGroupFixtures;

    private User $user;

    public function testIndex(): void
    {
        Passport::actingAs($this->user);
        $response = $this->getJson(route('api.v1.accounts.index'));
        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 5]]]);
    }

    public function testIndexCanFilterOnAccountType(): void
    {
        Passport::actingAs($this->user);
        $response = $this->getJson(route('api.v1.accounts.index').'?type=asset');
        $response->assertOk();
        $response->assertJson([
            'data' => [['attributes' => ['type' => 'asset']], ['attributes' => ['type' => 'asset']]],
            'meta' => ['pagination' => ['total' => 2]],
        ]);
    }

    public function testIndexFailsOnUnknownAccountType(): void
    {
        Passport::actingAs($this->user);
        $response = $this->getJson(route('api.v1.accounts.index').'?type=foobar');
        $response->assertUnprocessable();
        $response->assertJson(['errors' => ['type' => ['The selected type is invalid.']]]);
    }

    public function testIndexUsesExplicitRequestedGroupInsteadOfActiveGroup(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $activeAccount    = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET);
        $requestedAccount = $this->createAccountInGroup($requestedCreator, $fixture['requested_group'], AccountTypeEnum::ASSET);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.accounts.index', ['user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        self::assertSame((string) $requestedAccount->id, $response->json('data.0.id'));
        self::assertNotSame((string) $activeAccount->id, $response->json('data.0.id'));
        self::assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }

    public function testShowUsesExplicitRequestedGroup(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $requestedAccount = $this->createAccountInGroup($requestedCreator, $fixture['requested_group'], AccountTypeEnum::ASSET);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.accounts.show', [
            'account'       => $requestedAccount->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertOk();
        self::assertSame((string) $requestedAccount->id, $response->json('data.id'));
    }

    public function testShowFailsClosedWhenRouteAccountIsOutsideRequestedGroup(): void
    {
        $fixture       = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $activeAccount = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.accounts.show', [
            'account'       => $activeAccount->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertNotFound();
    }

    public function testIndexDeniesUnrelatedExplicitGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.accounts.index', ['user_group_id' => $fixture['unrelated_group']->id]));

        $response->assertUnauthorized();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();

        Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create();
        Account::factory()->for($this->user)->withType(AccountTypeEnum::REVENUE)->create();
        Account::factory()->for($this->user)->withType(AccountTypeEnum::EXPENSE)->create();
        Account::factory()->for($this->user)->withType(AccountTypeEnum::DEBT)->create();
        Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create();
    }
}
