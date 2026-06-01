<?php

/*
 * UsesSharedAdministrationContext.php
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

namespace FireflyIII\Support\Http\Controllers;

use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use FireflyIII\Support\Http\SharedAdministration\AdministrationResolver;
use FireflyIII\Support\Http\SharedAdministration\RouteRoleResolver;
use FireflyIII\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\View;

trait UsesSharedAdministrationContext
{
    protected function resolvedUserGroup(): ?UserGroup
    {
        $context = $this->resolvedAdministrationContext();
        if ($context instanceof AdministrationContext) {
            return $context->userGroup();
        }

        return null;
    }

    protected function applyResolvedUserGroup(object $target): void
    {
        $userGroup = $this->resolvedUserGroup();
        if (null !== $userGroup && method_exists($target, 'setUserGroup')) {
            $target->setUserGroup($userGroup);
        }
    }

    protected function appendResolvedUserGroupQuery(Paginator|AbstractPaginator $paginator): void
    {
        $userGroup = $this->resolvedUserGroup();
        if (null !== $userGroup && method_exists($paginator, 'appends')) {
            $paginator->appends(['user_group_id' => $userGroup->id]);
        }
    }

    protected function resolvedUserGroupQuery(): array
    {
        $userGroup = $this->resolvedUserGroup();

        return null === $userGroup ? [] : ['user_group_id' => $userGroup->id];
    }

    private function resolvedAdministrationContext(): ?AdministrationContext
    {
        $context = request()->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE);
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            $this->shareResolvedUserGroup($context->userGroup());

            return $context;
        }

        if (!app()->bound(AdministrationContext::class)) {
            return null;
        }

        $context = app(AdministrationContext::class);
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            $this->shareResolvedUserGroup($context->userGroup());

            return $context;
        }

        return $this->resolveSelectedUserGroup(request());
    }

    private function resolveSelectedUserGroup(Request $request): ?AdministrationContext
    {
        if ($this->requestHasUserGroupId($request)) {
            return null;
        }

        $user = $request->user() ?? auth()->user();
        if (!$user instanceof User || (int) $user->user_group_id <= 0) {
            return null;
        }

        $acceptedRoles = app(RouteRoleResolver::class)->acceptedRolesFor($request);
        if ([] === $acceptedRoles) {
            return null;
        }

        $context = app(AdministrationResolver::class)->resolve($request, $acceptedRoles);
        if ($context instanceof AdministrationContext && $context->hasResolvedAdministration()) {
            $this->shareResolvedUserGroup($context->userGroup());

            return $context;
        }

        return null;
    }

    private function requestHasUserGroupId(Request $request): bool
    {
        return $request->query->has('user_group_id')
            || $request->request->has('user_group_id')
            || ($request->isJson() && $request->json()->has('user_group_id'));
    }

    private function shareResolvedUserGroup(UserGroup $userGroup): void
    {
        View::share('userGroupId', $userGroup->id);
    }
}
