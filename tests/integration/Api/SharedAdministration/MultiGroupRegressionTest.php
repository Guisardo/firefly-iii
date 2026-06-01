<?php

/*
 * MultiGroupRegressionTest.php
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

namespace Tests\integration\Api\SharedAdministration;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @coversNothing
 */
final class MultiGroupRegressionTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    public function testExplicitGroupReadUsesRequestedGroupInsteadOfActiveOrUnrelatedGroups(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $user    = $fixture['user'];

        $this->createAccountInGroup($user, $fixture['active_group'], AccountTypeEnum::ASSET, 'Shared active only');
        $this->createAccountInGroup($user, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Shared requested only');
        $this->createAccountInGroup($user, $fixture['unrelated_group'], AccountTypeEnum::ASSET, 'Shared unrelated only');

        Passport::actingAs($user);
        $response = $this->getJson(route('api.v1.autocomplete.accounts', [
            'query'         => 'Shared',
            'type'          => 'asset',
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertOk();
        $this->assertSame(['Shared requested only'], array_column($response->json(), 'name'));
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testCrossGroupReadIsDeniedAndDoesNotMutateActiveGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $user    = $fixture['user'];

        $this->createAccountInGroup($user, $fixture['unrelated_group'], AccountTypeEnum::ASSET, 'Denied unrelated account');

        Passport::actingAs($user);
        $response = $this->getJson(route('api.v1.autocomplete.accounts', [
            'query'         => 'Denied',
            'type'          => 'asset',
            'user_group_id' => $fixture['unrelated_group']->id,
        ]));

        $response->assertUnauthorized();
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testMalformedGroupIdIsRejectedWithoutFallingBackToActiveGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $user    = $fixture['user'];

        $this->createAccountInGroup($user, $fixture['active_group'], AccountTypeEnum::ASSET, 'Malformed active account');

        Passport::actingAs($user);
        $response = $this->getJson(route('api.v1.autocomplete.accounts', [
            'query'         => 'Malformed',
            'type'          => 'asset',
            'user_group_id' => 'not-a-number',
        ]));

        $response->assertUnauthorized();
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }
}
