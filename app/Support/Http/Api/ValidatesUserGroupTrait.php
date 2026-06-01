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
use FireflyIII\Support\Http\SharedAdministration\AdministrationContext;
use FireflyIII\Support\Http\SharedAdministration\AdministrationResolver;
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
        $context = $request->attributes->get(AdministrationContext::REQUEST_ATTRIBUTE);
        if (!$context instanceof AdministrationContext || !$context->hasResolvedAdministration()) {
            /** @var AdministrationResolver $resolver */
            $resolver = app(AdministrationResolver::class);
            $context  = $resolver->resolve($request, $this->acceptedRoles);
        }

        if (!$context instanceof AdministrationContext || !$context->hasResolvedAdministration()) {
            throw new AuthorizationException((string) trans('validation.belongs_user_or_user_group'));
        }

        $this->user      = $context->user();
        $this->userGroup = $context->userGroup();

        Log::debug(sprintf('validateUserGroup: resolved group #%d via %s.', $this->userGroup->id, $context->source() ?? 'unknown'));

        return $this->userGroup;
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
