<?php

/*
 * ShowControllerTest.php
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

namespace Tests\integration\Api\Models\Transaction;

use FireflyIII\Enums\UserRoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\ShowController
 */
final class ShowControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    public function testIndexUsesExplicitRequestedGroupInsteadOfActiveGroup(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $activeGroup      = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);
        $requestedGroup   = $this->createWithdrawalInGroup($requestedCreator, $fixture['requested_group']);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.index', ['user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        self::assertSame((string) $requestedGroup->id, $response->json('data.0.id'));
        self::assertNotSame((string) $activeGroup->id, $response->json('data.0.id'));
        self::assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }

    public function testShowUsesExplicitRequestedGroup(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $requestedGroup   = $this->createWithdrawalInGroup($requestedCreator, $fixture['requested_group']);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.show', [
            'transactionGroup' => $requestedGroup->id,
            'user_group_id'    => $fixture['requested_group']->id,
        ]));

        $response->assertOk();
        self::assertSame((string) $requestedGroup->id, $response->json('data.id'));
    }

    public function testShowFailsClosedWhenRouteTransactionIsOutsideRequestedGroup(): void
    {
        $fixture     = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $activeGroup = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.show', [
            'transactionGroup' => $activeGroup->id,
            'user_group_id'    => $fixture['requested_group']->id,
        ]));

        $response->assertNotFound();
    }

    public function testIndexDeniesUnrelatedExplicitGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.index', ['user_group_id' => $fixture['unrelated_group']->id]));

        $response->assertUnauthorized();
    }

    public function testIndexWithoutExplicitGroupUsesSelectedDefaultAcrossMembers(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $activeCreator    = $this->createUserInGroup($fixture['active_group'], UserRoleEnum::OWNER);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $activeGroup      = $this->createWithdrawalInGroup($activeCreator, $fixture['active_group']);
        $this->createWithdrawalInGroup($requestedCreator, $fixture['requested_group']);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.index'));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        self::assertSame((string) $activeGroup->id, $response->json('data.0.id'));
    }

    public function testShowWithoutExplicitGroupUsesSelectedDefaultAcrossMembers(): void
    {
        $fixture       = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $activeCreator = $this->createUserInGroup($fixture['active_group'], UserRoleEnum::OWNER);
        $activeGroup   = $this->createWithdrawalInGroup($activeCreator, $fixture['active_group']);

        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.show', ['transactionGroup' => $activeGroup->id]));

        $response->assertOk();
        self::assertSame((string) $activeGroup->id, $response->json('data.id'));
        self::assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }
}
