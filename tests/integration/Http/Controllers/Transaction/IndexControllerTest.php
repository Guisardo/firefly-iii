<?php

/*
 * IndexControllerTest.php
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

namespace Tests\integration\Http\Controllers\Transaction;

use FireflyIII\User;
use Override;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Http\Controllers\Transaction\IndexController
 */
final class IndexControllerTest extends TestCase
{
    private ?User $user = null;

    public function testAllTransactionIndexRendersPeriodOverview(): void
    {
        $response = $this->get(route('transactions.index', ['all', '2024-01-01', '2024-01-31']));

        $response->assertOk();
        $response->assertViewHas('objectType', 'all');
        $response->assertSee('All transactions between', false);
        $response->assertDontSee(route('transactions.create', ['all']), false);
    }

    public function testAllTransactionIndexRendersUnfilteredList(): void
    {
        $response = $this->get(route('transactions.index.all', ['all']));

        $response->assertOk();
        $response->assertViewHas('objectType', 'all');
        $response->assertSee('All transactions', false);
        $response->assertDontSee(route('transactions.create', ['all']), false);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->user instanceof User) {
            $this->user = $this->createAuthenticatedUser();
        }
        $this->actingAs($this->user);
    }
}
