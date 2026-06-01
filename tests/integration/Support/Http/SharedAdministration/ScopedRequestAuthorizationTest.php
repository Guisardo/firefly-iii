<?php

/*
 * ScopedRequestAuthorizationTest.php
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

use FireflyIII\Api\V1\Requests\Models\Account\StoreRequest as AccountStoreRequest;
use FireflyIII\Api\V1\Requests\Models\Account\UpdateRequest as AccountUpdateRequest;
use FireflyIII\Api\V1\Requests\Models\Transaction\StoreRequest as TransactionStoreRequest;
use FireflyIII\Api\V1\Requests\Models\Transaction\UpdateRequest as TransactionUpdateRequest;
use FireflyIII\Api\V1\Requests\Models\UserGroup\DestroyRequest as UserGroupDestroyRequest;
use FireflyIII\Api\V1\Requests\Models\UserGroup\StoreRequest as UserGroupStoreRequest;
use FireflyIII\Api\V1\Requests\Models\UserGroup\UpdateMembershipRequest;
use FireflyIII\Api\V1\Requests\Models\UserGroup\UpdateRequest as UserGroupUpdateRequest;
use FireflyIII\Api\V1\Requests\Models\UserGroup\UseRequest as UserGroupUseRequest;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Support\Http\SharedAdministration\RouteRoleResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @coversNothing
 */
final class ScopedRequestAuthorizationTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    public function testScopedRequestClassesDoNotRelyOnEmptyAcceptedRoles(): void
    {
        foreach ($this->scopedRouteRequestClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $roles      = $reflection->getDefaultProperties()['acceptedRoles'] ?? [];

            if ([] !== $roles) {
                self::assertContainsOnlyInstancesOf(UserRoleEnum::class, $roles, sprintf('%s must only declare UserRoleEnum accepted roles.', $class));

                continue;
            }

            if (array_key_exists($class, $this->documentedEmptyAcceptedRoleExceptions())) {
                self::assertNotSame('', $this->documentedEmptyAcceptedRoleExceptions()[$class], sprintf('%s must document why empty acceptedRoles are safe.', $class));

                continue;
            }

            $method = $reflection->getMethod('authorize');
            self::assertSame($class, $method->getDeclaringClass()->getName(), sprintf('%s must override authorize() when acceptedRoles is empty.', $class));
            self::assertStringContainsString(
                'authorizeSelectedUserGroup',
                file_get_contents((string) $reflection->getFileName()),
                sprintf('%s must delegate explicit group checks to the selected-group authorizer.', $class)
            );
        }
    }

    public function testScopedRoutesResolveNonEmptySelectedGroupRoles(): void
    {
        $resolver = app(RouteRoleResolver::class);

        foreach ($this->scopedRoutes() as $route) {
            $request = Request::create('/'.$route->uri(), $this->routeMethod($route), ['user_group_id' => '1']);
            $request->setRouteResolver(static fn () => $route);
            $roles   = $resolver->acceptedRolesFor($request);

            self::assertNotSame([], $roles, sprintf('%s must resolve selected-group roles before request validation.', (string) $route->getName()));
            self::assertContainsOnlyInstancesOf(UserRoleEnum::class, $roles, sprintf('%s must resolve UserRoleEnum roles only.', (string) $route->getName()));
        }
    }

    public function testAccountUpdateRequestDeniesRouteAccountOutsideSelectedGroup(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $account = $this->createAccountInGroup($fixture['user'], $fixture['active_group']);
        $request = AccountUpdateRequest::create('/api/v1/accounts/'.$account->id, 'PUT', ['user_group_id' => $fixture['requested_group']->id]);
        $request->setRouteResolver(static fn () => new class($account) {
            public function __construct(private readonly mixed $account) {}

            public function parameter(string $key): mixed
            {
                return 'account' === $key ? $this->account : null;
            }
        });
        $this->actingAs($fixture['user']);

        self::assertFalse($request->authorize());
    }

    public function testTransactionUpdateRequestDeniesRouteTransactionOutsideSelectedGroup(): void
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $transactionGroup = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);
        $request          = TransactionUpdateRequest::create('/api/v1/transactions/'.$transactionGroup->id, 'PUT', ['user_group_id' => $fixture['requested_group']->id]);
        $request->setRouteResolver(static fn () => new class($transactionGroup) {
            public function __construct(private readonly mixed $transactionGroup) {}

            public function parameter(string $key): mixed
            {
                return 'transactionGroup' === $key ? $this->transactionGroup : null;
            }
        });
        $this->actingAs($fixture['user']);

        self::assertFalse($request->authorize());
    }

    public function testUserGroupWriteRequestsDeclareAuthorization(): void
    {
        foreach ($this->userGroupWriteRequests() as $class) {
            $reflection = new ReflectionClass($class);
            $method     = $reflection->getMethod('authorize');

            self::assertSame($class, $method->getDeclaringClass()->getName(), sprintf('%s must declare explicit authorization.', $class));
            self::assertStringContainsString(
                'AuthorizesUserGroupRequests',
                file_get_contents((string) $reflection->getFileName()),
                sprintf('%s must use the fail-closed user-group authorization helpers.', $class)
            );
        }
    }

    public function testUserGroupIdRequestAuditFindsAllScopedRequestClasses(): void
    {
        $expected = $this->scopedRequests();
        $actual   = $this->discoverUserGroupIdRequests();

        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    private function scopedRequests(): array
    {
        return [
            AccountStoreRequest::class,
            AccountUpdateRequest::class,
            TransactionStoreRequest::class,
            TransactionUpdateRequest::class,
            UserGroupDestroyRequest::class,
            UpdateMembershipRequest::class,
            UserGroupUpdateRequest::class,
            UserGroupUseRequest::class,
        ];
    }

    private function scopedRouteRequestClasses(): array
    {
        $classes = [];

        foreach ($this->scopedRoutes() as $route) {
            $controller = $this->controllerAction($route);
            if (null === $controller) {
                continue;
            }

            [$class, $method] = $controller;
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $name = $type->getName();
                if (str_starts_with($name, 'FireflyIII\\Api\\V1\\Requests\\Models\\')
                    && is_subclass_of($name, FormRequest::class)) {
                    $classes[] = $name;
                }
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    private function scopedRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), static function ($route): bool {
            $name = (string) $route->getName();

            return str_starts_with($name, 'api.v1.accounts.')
                || str_starts_with($name, 'api.v1.transactions.')
                || str_starts_with($name, 'api.v1.transaction-journals.')
                || str_starts_with($name, 'api.v1.user-groups.');
        }));
    }

    private function controllerAction($route): ?array
    {
        $controller = $route->getAction('controller') ?? $route->getAction('uses') ?? null;
        if (!is_string($controller) || !str_contains($controller, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controller, 2);
        if (!class_exists($class)) {
            $namespace = $route->getAction('namespace') ?? null;
            if (!is_string($namespace) || !class_exists($namespace.'\\'.$class)) {
                return null;
            }
            $class     = $namespace.'\\'.$class;
        }

        return [$class, $method];
    }

    private function routeMethod($route): string
    {
        foreach ($route->methods() as $method) {
            if ('HEAD' !== $method) {
                return $method;
            }
        }

        return 'GET';
    }

    private function documentedEmptyAcceptedRoleExceptions(): array
    {
        return [
            \FireflyIII\Api\V1\Requests\Models\Account\ShowRequest::class => 'Read-only account filters are guarded by RouteRoleResolver before validation.',
            UserGroupStoreRequest::class                                  => 'New group creation has no existing route group; explicit selected groups are guarded by RouteRoleResolver before validation.',
        ];
    }

    private function userGroupWriteRequests(): array
    {
        return [
            UserGroupDestroyRequest::class,
            UserGroupStoreRequest::class,
            UpdateMembershipRequest::class,
            UserGroupUpdateRequest::class,
            UserGroupUseRequest::class,
        ];
    }

    private function discoverUserGroupIdRequests(): array
    {
        $classes  = [
            UserGroupDestroyRequest::class,
            UpdateMembershipRequest::class,
            UserGroupUpdateRequest::class,
            UserGroupUseRequest::class,
        ];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Api/V1/Requests')));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents) || !str_contains($contents, 'user_group_id')) {
                continue;
            }

            $class = $this->classFromPath($file);
            if (null === $class || !is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    private function classFromPath(SplFileInfo $file): ?string
    {
        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $class    = 'FireflyIII\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        return class_exists($class) ? $class : null;
    }
}
