<?php

/*
 * RouteBinderUserGroupTest.php
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

namespace Tests\integration\Support\Binder;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use FireflyIII\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Override;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Models\Account
 * @covers \FireflyIII\Models\TransactionGroup
 * @covers \FireflyIII\Support\Binder\ResolvesUserGroupForRouteBinding
 */
final class RouteBinderUserGroupTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    public function testAccountBinderUsesResolvedUserGroupForExplicitRequest(): void
    {
        $fixture      = $this->createMultiGroupUserFixture();
        $groupUser    = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $account      = $this->createAccountInGroup($groupUser, $fixture['requested_group'], AccountTypeEnum::ASSET);

        $this->actingAs($fixture['user']);
        $this->setExplicitUserGroupRequest($fixture['user'], $fixture['requested_group']);

        $bound = Account::routeBinder((string) $account->id);

        $this->assertSame($account->id, $bound->id);
        $this->assertSame($fixture['requested_group']->id, $bound->user_group_id);
    }

    public function testAccountBinderPreservesLegacyUserBindingWithoutExplicitRequest(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $account = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET);

        $this->actingAs($fixture['user']);
        $this->setRequestWithoutExplicitUserGroup($fixture['user'], $fixture['requested_group']);

        $bound = Account::routeBinder((string) $account->id);

        $this->assertSame($account->id, $bound->id);
        $this->assertSame($fixture['active_group']->id, $bound->user_group_id);
    }

    public function testAccountBinderFailsClosedWhenExplicitRequestHasNoResolvedUserGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $account = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET);

        $this->actingAs($fixture['user']);
        app()->instance('request', Request::create('/api/v1/accounts/'.$account->id, 'GET', ['user_group_id' => $fixture['requested_group']->id]));

        $this->expectException(NotFoundHttpException::class);

        Account::routeBinder((string) $account->id);
    }

    public function testTransactionGroupBinderUsesResolvedUserGroupForExplicitRequest(): void
    {
        $fixture      = $this->createMultiGroupUserFixture();
        $groupUser    = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $group        = $this->createWithdrawalInGroup($groupUser, $fixture['requested_group']);

        $this->actingAs($fixture['user']);
        $this->setExplicitUserGroupRequest($fixture['user'], $fixture['requested_group']);

        $bound = TransactionGroup::routeBinder((string) $group->id);

        $this->assertSame($group->id, $bound->id);
        $this->assertSame($fixture['requested_group']->id, $bound->user_group_id);
    }

    public function testTransactionGroupBinderDeniesObjectsOutsideResolvedUserGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $group   = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);

        $this->actingAs($fixture['user']);
        $this->setExplicitUserGroupRequest($fixture['user'], $fixture['requested_group']);

        $this->expectException(NotFoundHttpException::class);

        TransactionGroup::routeBinder((string) $group->id);
    }

    public function testTransactionGroupBinderCanUseExplicitRouteUserGroup(): void
    {
        $fixture   = $this->createMultiGroupUserFixture();
        $groupUser = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $group     = $this->createWithdrawalInGroup($groupUser, $fixture['requested_group']);
        $route     = new Route('GET', '/api/v1/user-groups/{userGroup}/transactions/{transactionGroup}', []);
        $route->bind(Request::create(sprintf('/api/v1/user-groups/%d/transactions/%d', $fixture['requested_group']->id, $group->id), 'GET'));
        $route->setParameter('userGroup', $fixture['requested_group']);

        $this->actingAs($fixture['user']);

        $bound = TransactionGroup::routeBinder((string) $group->id, $route);

        $this->assertSame($group->id, $bound->id);
        $this->assertSame($fixture['requested_group']->id, $bound->user_group_id);
    }

    public function testTransactionGroupBinderFailsClosedOnRouteAndRequestGroupMismatch(): void
    {
        $fixture   = $this->createMultiGroupUserFixture();
        $groupUser = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $group     = $this->createWithdrawalInGroup($groupUser, $fixture['requested_group']);
        $request   = Request::create(sprintf('/api/v1/user-groups/%d/transactions/%d', $fixture['requested_group']->id, $group->id), 'GET', [
            'user_group_id' => $fixture['active_group']->id,
        ]);
        $route     = new Route('GET', '/api/v1/user-groups/{userGroup}/transactions/{transactionGroup}', []);
        $route->bind($request);
        $route->setParameter('userGroup', $fixture['requested_group']);

        $this->actingAs($fixture['user']);
        app()->instance('request', $request);

        $this->expectException(NotFoundHttpException::class);

        TransactionGroup::routeBinder((string) $group->id, $route);
    }

    #[Override]
    protected function tearDown(): void
    {
        app()->forgetInstance(UserGroup::class);
        app()->forgetInstance(AdministrationContext::class);

        parent::tearDown();
    }

    private function setExplicitUserGroupRequest(User $user, UserGroup $userGroup): void
    {
        $request = Request::create('/api/v1/shared', 'GET', ['user_group_id' => $userGroup->id]);
        /** @var AdministrationContext $context */
        $context = app(AdministrationContext::class);
        $context->set($user, $userGroup, [UserRoleEnum::OWNER]);
        $request->attributes->set(AdministrationContext::REQUEST_ATTRIBUTE, $context);
        app()->instance('request', $request);
    }

    private function setRequestWithoutExplicitUserGroup(User $user, UserGroup $boundUserGroup): void
    {
        app()->instance('request', Request::create('/api/v1/shared', 'GET'));
        /** @var AdministrationContext $context */
        $context = app(AdministrationContext::class);
        $context->clear();
    }
}
