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

    public function testTransactionCreateHonorsExplicitRequestedAdministrationForManager(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal', 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertOk();
        $response->assertSee(sprintf('/transactions/create/withdrawal?user_group_id=%d', $fixture['requested_group']->id), false);
    }

    public function testTransactionCreateDeniesExplicitRequestedAdministrationForReadOnlyUser(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $this->actingAs($fixture['user']);

        $response = $this->get(route('transactions.create', ['withdrawal', 'user_group_id' => $fixture['requested_group']->id]));

        $response->assertForbidden();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('view.layout', 'v2');
    }
}
