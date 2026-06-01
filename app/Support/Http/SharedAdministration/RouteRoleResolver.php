<?php

/*
 * RouteRoleResolver.php
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

namespace FireflyIII\Support\Http\SharedAdministration;

use FireflyIII\Enums\UserRoleEnum;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionException;

class RouteRoleResolver
{
    private const array WEB_READ_ROUTES = [
        'accounts.index',
        'accounts.inactive.index',
        'accounts.show',
        'accounts.show.all',
        'transactions.index',
        'transactions.index.all',
        'transactions.show',
        'chart.transactions.categories',
        'chart.transactions.budgets',
        'chart.transactions.destinationAccounts',
        'chart.transactions.sourceAccounts',
    ];

    private const array WEB_MANAGE_TRANSACTION_ROUTES = [
        'accounts.edit',
        'accounts.update',
        'accounts.delete',
        'accounts.destroy',
    ];

    public function acceptedRolesFor(Request $request): array
    {
        $route = $request->route();
        if (null === $route) {
            return [];
        }

        $routeName = (string) $route->getName();
        if (str_starts_with($routeName, 'api.v1.user-groups.')) {
            return $this->userGroupRoles($request, $routeName);
        }
        if (in_array($routeName, self::WEB_READ_ROUTES, true)) {
            return [UserRoleEnum::READ_ONLY];
        }
        if (in_array($routeName, self::WEB_MANAGE_TRANSACTION_ROUTES, true)) {
            return [UserRoleEnum::MANAGE_TRANSACTIONS];
        }
        if (str_starts_with($routeName, 'api.v1.accounts.')
            || str_starts_with($routeName, 'api.v1.transactions.')
            || str_starts_with($routeName, 'api.v1.transaction-journals.')) {
            return $this->accountOrTransactionRoles($request);
        }

        $action          = $route->getAction();
        $controllerRoles = $this->controllerAcceptedRoles($action['controller'] ?? $action['uses'] ?? null);
        if ([] !== $controllerRoles) {
            return $controllerRoles;
        }

        if (str_starts_with($routeName, 'api.v1.')) {
            return $this->readOrManageRoles($request);
        }

        return [];
    }

    private function accountOrTransactionRoles(Request $request): array
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return [UserRoleEnum::READ_ONLY];
        }

        return [UserRoleEnum::MANAGE_TRANSACTIONS];
    }

    private function controllerAcceptedRoles(mixed $controller): array
    {
        if (!is_string($controller) || !str_contains($controller, '@')) {
            return [];
        }

        [$class] = explode('@', $controller, 2);
        if (!class_exists($class)) {
            return [];
        }

        try {
            $properties = (new ReflectionClass($class))->getDefaultProperties();
        } catch (ReflectionException) {
            return [];
        }

        $roles = $properties['acceptedRoles'] ?? [];
        if (!is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, static fn (mixed $role): bool => $role instanceof UserRoleEnum));
    }

    private function readOrManageRoles(Request $request): array
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return [UserRoleEnum::READ_ONLY];
        }

        return [UserRoleEnum::MANAGE_TRANSACTIONS];
    }

    private function userGroupRoles(Request $request, string $routeName): array
    {
        if (str_ends_with($routeName, '.index') || str_ends_with($routeName, '.store')) {
            return [];
        }
        if (str_ends_with($routeName, '.use')) {
            return [UserRoleEnum::READ_ONLY, UserRoleEnum::MANAGE_TRANSACTIONS, UserRoleEnum::VIEW_MEMBERSHIPS];
        }
        if (str_ends_with($routeName, '.delete') || str_ends_with($routeName, '.destroy')) {
            return [UserRoleEnum::OWNER];
        }
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return [UserRoleEnum::READ_ONLY];
        }

        return [UserRoleEnum::FULL];
    }
}
