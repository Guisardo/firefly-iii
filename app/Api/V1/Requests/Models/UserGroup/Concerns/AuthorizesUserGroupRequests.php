<?php

/*
 * AuthorizesUserGroupRequests.php
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

namespace FireflyIII\Api\V1\Requests\Models\UserGroup\Concerns;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use FireflyIII\Support\Http\SharedAdministration\AdministrationRoleSet;
use FireflyIII\User;

trait AuthorizesUserGroupRequests
{
    protected function authorizeAuthenticatedUser(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && true !== $user->blocked;
    }

    protected function authorizeRouteUserGroup(array $acceptedRoles): bool
    {
        if (!$this->authorizeAuthenticatedUser() || [] === $acceptedRoles) {
            return false;
        }

        $userGroup = $this->route()?->parameter('userGroup');
        if (!$userGroup instanceof UserGroup) {
            return false;
        }
        if (!$this->routeUserGroupMatchesResolvedAdministration($userGroup)) {
            return false;
        }

        /** @var User $user */
        $user              = auth()->user();
        $allowedRoleTitles = AdministrationRoleSet::allowedTitles($acceptedRoles);
        if ([] === $allowedRoleTitles) {
            return false;
        }

        return GroupMembership::query()
            ->where('user_id', $user->id)
            ->where('user_group_id', $userGroup->id)
            ->whereHas('userRole', static function ($query) use ($allowedRoleTitles): void {
                $query->whereIn('title', $allowedRoleTitles);
            })
            ->exists()
        ;
    }

    protected function actingUserHasRoleInGroup(UserGroup $userGroup, UserRoleEnum $role): bool
    {
        if (!$this->authorizeAuthenticatedUser()) {
            return false;
        }

        /** @var User $user */
        $user = auth()->user();

        return GroupMembership::query()
            ->where('user_id', $user->id)
            ->where('user_group_id', $userGroup->id)
            ->whereHas('userRole', static function ($query) use ($role): void {
                $query->where('title', $role->value);
            })
            ->exists()
        ;
    }

    private function routeUserGroupMatchesResolvedAdministration(UserGroup $userGroup): bool
    {
        $context = $this->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE);
        if (!$context instanceof AdministrationContext || !$context->hasResolvedAdministration()) {
            return true;
        }

        return $context->userGroup()->id === $userGroup->id;
    }
}
