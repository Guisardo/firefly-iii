<?php

/*
 * AdministrationResolverTest.php
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

namespace Tests\integration\Support\Http\SharedAdministration;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use FireflyIII\Support\Http\SharedAdministration\AdministrationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

class AdministrationResolverTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    public function testSelectedDefaultResolvesWhenUserGroupIdIsOmitted(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $request = $this->requestFor($fixture['user'], []);

        $context = $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);

        $this->assertInstanceOf(AdministrationContext::class, $context);
        $this->assertSame($fixture['active_group']->id, $context->userGroup()->id);
        $this->assertSame($context, $request->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE));
        $this->assertFalse($context->hasExplicitUserGroupId());
        $this->assertSame(AdministrationContext::SOURCE_SELECTED_DEFAULT, $context->source());
    }

    public function testNoUserGroupIdWithoutAcceptedRolesLeavesContextUnresolved(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $request = $this->requestFor($fixture['user'], []);

        $result  = $this->resolver()->resolve($request, []);

        $this->assertNull($result);
        $this->assertFalse(app(AdministrationContext::class)->hasResolvedAdministration());
    }

    public function testExplicitUserGroupIdResolvesRequestedGroupInsteadOfSelectedDefault(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $request = $this->requestFor($fixture['user'], ['user_group_id' => $fixture['requested_group']->id]);

        $context = $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);

        $this->assertInstanceOf(AdministrationContext::class, $context);
        $this->assertSame($fixture['requested_group']->id, $context->userGroup()->id);
        $this->assertNotSame($fixture['active_group']->id, $context->userGroup()->id);
        $this->assertSame($context, $request->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE));
        $this->assertTrue($context->hasExplicitUserGroupId());
        $this->assertSame(AdministrationContext::SOURCE_EXPLICIT, $context->source());
    }

    public function testStaleSelectedDefaultGroupIsDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['active_group']->delete();
        $request = $this->requestFor($fixture['user']->refresh(), []);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    public function testSelectedDefaultWithoutMembershipIsDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['user']->groupMemberships()->where('user_group_id', $fixture['active_group']->id)->delete();
        $request = $this->requestFor($fixture['user']->refresh(), []);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    public function testBlockedUserIsDeniedForSelectedDefault(): void
    {
        $fixture                  = $this->createMultiGroupUserFixture(UserRoleEnum::OWNER);
        $fixture['user']->blocked = true;
        $fixture['user']->save();
        $request                  = $this->requestFor($fixture['user']->refresh(), []);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    public function testSelectedDefaultRoleDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['user']->groupMemberships()->where('user_group_id', $fixture['active_group']->id)->delete();
        $this->createGroupMembership($fixture['user'], $fixture['active_group'], UserRoleEnum::READ_ONLY);
        $request = $this->requestFor($fixture['user']->refresh(), []);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::MANAGE_TRANSACTIONS]);
    }

    public function testCrossGroupRequestIsDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $request = $this->requestFor($fixture['user'], ['user_group_id' => $fixture['unrelated_group']->id]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    public function testNullExplicitUserGroupIdIsDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $request = $this->requestFor($fixture['user'], ['user_group_id' => null]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    #[DataProvider('malformedUserGroupIds')]
    public function testMalformedExplicitUserGroupIdIsDenied(mixed $userGroupId): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $request = $this->requestFor($fixture['user'], ['user_group_id' => $userGroupId]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    public function testReadOnlyUserCannotResolveWriteAdministration(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $request = $this->requestFor($fixture['user'], ['user_group_id' => $fixture['requested_group']->id]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::MANAGE_TRANSACTIONS]);
    }

    public function testFullUserCannotResolveOwnerOnlyAdministration(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::FULL);
        $request = $this->requestFor($fixture['user'], ['user_group_id' => $fixture['requested_group']->id]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::OWNER]);
    }

    public function testJsonUserGroupIdResolvesRequestedGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $request = $this->jsonRequestFor($fixture['user'], ['user_group_id' => $fixture['requested_group']->id]);

        $context = $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);

        $this->assertInstanceOf(AdministrationContext::class, $context);
        $this->assertSame($fixture['requested_group']->id, $context->userGroup()->id);
        $this->assertTrue($context->hasExplicitUserGroupId());
        $this->assertSame(AdministrationContext::SOURCE_EXPLICIT, $context->source());
    }

    public function testConflictingQueryAndFormUserGroupIdsAreDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $request = Request::create(
            sprintf('/api/v1/accounts?user_group_id=%d', $fixture['active_group']->id),
            'POST',
            ['user_group_id' => $fixture['requested_group']->id]
        );
        $request->setUserResolver(static fn () => $fixture['user']);

        try {
            $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
            $this->fail('Conflicting query and form user_group_id values must fail closed.');
        } catch (AuthorizationException|ConflictHttpException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testConflictingQueryAndJsonUserGroupIdsAreDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $request = $this->jsonRequestFor(
            $fixture['user'],
            ['user_group_id' => $fixture['requested_group']->id],
            sprintf('?user_group_id=%d', $fixture['active_group']->id)
        );

        try {
            $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
            $this->fail('Conflicting query and JSON user_group_id values must fail closed.');
        } catch (AuthorizationException|ConflictHttpException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testBlockedUserIsDenied(): void
    {
        $fixture                  = $this->createMultiGroupUserFixture(UserRoleEnum::OWNER);
        $fixture['user']->blocked = true;
        $fixture['user']->save();
        $request                  = $this->requestFor($fixture['user']->refresh(), ['user_group_id' => $fixture['requested_group']->id]);

        $this->expectException(AuthorizationException::class);

        $this->resolver()->resolve($request, [UserRoleEnum::READ_ONLY]);
    }

    private function requestFor($user, array $parameters): Request
    {
        $request = Request::create('/api/v1/accounts', 'GET', $parameters);
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    public static function malformedUserGroupIds(): array
    {
        return [
            'empty string'   => [''],
            'zero'           => ['0'],
            'negative'       => ['-1'],
            'float string'   => ['1.5'],
            'mixed string'   => ['1abc'],
            'boolean'        => [true],
            'array'          => [[[1]]],
            'overflow'       => ['92233720368547758070'],
        ];
    }

    private function resolver(): AdministrationResolver
    {
        return app(AdministrationResolver::class);
    }

    private function jsonRequestFor($user, array $payload, string $query = ''): Request
    {
        $request = Request::create(
            '/api/v1/accounts'.$query,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload)
        );
        $request->setUserResolver(static fn () => $user);

        return $request;
    }
}
