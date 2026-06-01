<?php

/*
 * SharedAdministrationAuditTest.php
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
use FireflyIII\Events\Model\UserGroup\SharedAdministrationAccessDenied;
use FireflyIII\Events\Model\UserGroup\SharedAdministrationGroupSelected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @coversNothing
 */
final class SharedAdministrationAuditTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    public function testExplicitGroupSelectionEmitsAuditEventWithoutChangingActiveGroup(): void
    {
        Event::fake([SharedAdministrationGroupSelected::class]);

        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $user    = $fixture['user'];
        $this->createAccountInGroup($user, $fixture['requested_group'], AccountTypeEnum::ASSET, 'Audit selected account');

        Passport::actingAs($user);
        $response = $this->getJson(route('api.v1.autocomplete.accounts', [
            'query'         => 'Audit selected',
            'type'          => 'asset',
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertOk();
        Event::assertDispatched(SharedAdministrationGroupSelected::class, function (SharedAdministrationGroupSelected $event) use ($fixture, $user): bool {
            return $event->user->is($user)
                && $event->userGroup->is($fixture['requested_group'])
                && $event->defaultUserGroupId === $fixture['active_group']->id
                && $event->acceptedRoles === [UserRoleEnum::READ_ONLY->value];
        });
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }

    public function testDeniedExplicitGroupSelectionEmitsAuditEventWithoutChangingActiveGroup(): void
    {
        Event::fake([SharedAdministrationAccessDenied::class]);

        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $user    = $fixture['user'];

        Passport::actingAs($user);
        $response = $this->getJson(route('api.v1.autocomplete.accounts', [
            'query'         => 'Audit denied',
            'type'          => 'asset',
            'user_group_id' => $fixture['unrelated_group']->id,
        ]));

        $response->assertUnauthorized();
        Event::assertDispatched(SharedAdministrationAccessDenied::class, function (SharedAdministrationAccessDenied $event) use ($fixture, $user): bool {
            return $event->user->is($user)
                && $event->requestedUserGroupId === $fixture['unrelated_group']->id
                && $event->defaultUserGroupId === $fixture['active_group']->id
                && $event->reason === 'missing_membership'
                && $event->acceptedRoles === [UserRoleEnum::READ_ONLY->value];
        });
        $this->assertSame($fixture['active_group']->id, $user->refresh()->user_group_id);
    }
}
