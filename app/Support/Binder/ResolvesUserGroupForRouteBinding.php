<?php

/*
 * ResolvesUserGroupForRouteBinding.php
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

namespace FireflyIII\Support\Binder;

use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

class ResolvesUserGroupForRouteBinding
{
    public static function hasExplicitUserGroup(?Route $route = null): bool
    {
        if (null !== self::routeParameter($route, 'userGroup')) {
            return true;
        }

        $request = self::request();

        return $request instanceof Request && $request->has('user_group_id');
    }

    public static function resolvedUserGroup(?Route $route = null): ?UserGroup
    {
        $routeGroup = self::routeParameter($route, 'userGroup');
        if ($routeGroup instanceof UserGroup) {
            $requestedGroupId = self::requestedUserGroupId();
            if (null !== $requestedGroupId && $routeGroup->id !== $requestedGroupId) {
                return null;
            }

            return $routeGroup;
        }

        if (!self::hasExplicitUserGroup($route)) {
            return null;
        }

        $context = self::administrationContext();
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            return $context->userGroup();
        }

        return null;
    }

    private static function requestedUserGroupId(): ?int
    {
        $request = self::request();
        if (!$request instanceof Request || !$request->has('user_group_id')) {
            return null;
        }

        $userGroupId = (int) $request->get('user_group_id');

        return $userGroupId > 0 ? $userGroupId : null;
    }

    private static function request(): ?Request
    {
        if (!app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private static function administrationContext(): ?AdministrationContext
    {
        $context = self::request()?->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE);
        if ($context instanceof AdministrationContext) {
            return $context;
        }

        if (!app()->bound(AdministrationContext::class)) {
            return null;
        }

        try {
            $context = app(AdministrationContext::class);

            return $context instanceof AdministrationContext ? $context : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function routeParameter(?Route $route, string $parameter): mixed
    {
        if (null === $route) {
            return null;
        }

        try {
            return $route->parameter($parameter);
        } catch (Throwable) {
            return null;
        }
    }
}
