<?php

/*
 * ValidatesUserGroupTrait.php
 * Copyright (c) 2023 james@firefly-iii.org
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

namespace FireflyIII\Support\Http\Api;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Events\Model\UserGroup\SharedAdministrationAccessDenied;
use FireflyIII\Events\Model\UserGroup\SharedAdministrationGroupSelected;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\UserGroup\UserGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trait ValidatesUserGroupTrait
 */
trait ValidatesUserGroupTrait
{
    protected User $user;
    protected UserGroup $userGroup;

    /**
     * An "undocumented" filter
     *
     * TODO add this filter to the API docs.
     *
     * @throws AuthorizationException
     * @throws AuthenticationException
     */
    protected function validateUserGroup(Request $request): UserGroup
    {
        Log::debug(sprintf('validateUserGroup: %s', static::class));
        if (!auth()->check()) {
            Log::debug('validateUserGroup: user is not logged in, return NULL.');

            throw new AuthenticationException();
        }

        /** @var User $user */
        $user        = auth()->user();
        $groupId     = ResolvesUserGroupParameter::resolve($request, (int) $user->user_group_id);
        $explicit    = ResolvesUserGroupParameter::hasExplicitUserGroup($request);
        if (!$explicit) {
            Log::debug(sprintf('validateUserGroup: no user group submitted, use default group #%d.', $groupId));
        }
        if ($explicit) {
            Log::debug(sprintf('validateUserGroup: user group submitted, search for memberships in group #%d.', $groupId));
        }

        /** @var UserGroupRepositoryInterface $repository */
        $repository  = app(UserGroupRepositoryInterface::class);
        $repository->setUser($user);
        $memberships = $repository->getMembershipsFromGroupId($groupId);

        if (0 === $memberships->count()) {
            Log::debug(sprintf('validateUserGroup: user has no access to group #%d.', $groupId));
            $this->auditExplicitGroupDenial($explicit, $user, $groupId, 'missing_membership');

            throw new AuthorizationException((string) trans('validation.no_access_group'));
        }

        // need to get the group from the membership:
        $group       = $repository->getById($groupId);
        if (null === $group) {
            Log::debug(sprintf('validateUserGroup: group #%d does not exist.', $groupId));
            $this->auditExplicitGroupDenial($explicit, $user, $groupId, 'missing_group');

            throw new AuthorizationException((string) trans('validation.belongs_user_or_user_group'));
        }
        Log::debug(sprintf('validateUserGroup: validate access of user to group #%d ("%s").', $groupId, $group->title));
        if (0 === count($this->acceptedRoles)) {
            Log::debug('validateUserGroup: no roles defined, so no access.');
            $this->auditExplicitGroupDenial($explicit, $user, $groupId, 'no_accepted_roles');

            throw new AuthorizationException((string) trans('validation.no_accepted_roles_defined'));
        }
        Log::debug(sprintf('validateUserGroup: have %d roles to check.', count($this->acceptedRoles)), $this->acceptedRoles);

        /** @var UserRoleEnum $role */
        foreach ($this->acceptedRoles as $role) {
            if ($user->hasRoleInGroupOrOwner($group, $role)) {
                Log::debug(sprintf('validateUserGroup: User has role "%s" in group #%d, return the group.', $role->value, $groupId));
                $this->userGroup = $group;
                $this->user      = $user;
                $this->auditExplicitGroupSelection($explicit, $user, $group);

                return $group;
            }
            Log::debug(sprintf('validateUserGroup: User does NOT have role "%s" in group #%d, continue searching.', $role->value, $groupId));
        }

        Log::debug('validateUserGroup: User does NOT have enough rights to access endpoint.');
        $this->auditExplicitGroupDenial($explicit, $user, $groupId, 'insufficient_role');

        throw new AuthorizationException((string) trans('validation.belongs_user_or_user_group'));
    }

    private function auditExplicitGroupDenial(bool $explicit, User $user, int $groupId, string $reason): void
    {
        if (!$explicit) {
            return;
        }

        event(new SharedAdministrationAccessDenied($user, $groupId, (int) $user->user_group_id, static::class, $reason, $this->acceptedRoleValues()));
    }

    private function auditExplicitGroupSelection(bool $explicit, User $user, UserGroup $group): void
    {
        if (!$explicit) {
            return;
        }

        event(new SharedAdministrationGroupSelected($user, $group, (int) $user->user_group_id, static::class, $this->acceptedRoleValues()));
    }

    private function acceptedRoleValues(): array
    {
        return array_map(static fn (UserRoleEnum $role): string => $role->value, $this->acceptedRoles);
    }
}
