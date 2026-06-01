<?php

/*
 * AdministrationResolver.php
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

use FireflyIII\Events\Model\UserGroup\SharedAdministrationAccessDenied;
use FireflyIII\Events\Model\UserGroup\SharedAdministrationGroupSelected;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Http\Api\ResolvesUserGroupParameter;
use FireflyIII\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdministrationResolver
{
    public function __construct(
        private readonly AdministrationContext $context
    ) {}

    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function resolve(Request $request, array $acceptedRoles): ?AdministrationContext
    {
        $this->context->clear();
        $explicitParameter = ResolvesUserGroupParameter::hasExplicitUserGroup($request);
        $routeGroupId      = $this->routeUserGroupId($request);
        if (!$explicitParameter && null === $routeGroupId && [] === $acceptedRoles) {
            return null;
        }

        $user = $request->user() ?? auth()->user();
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }
        if (true === $user->blocked) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }
        if ([] === $acceptedRoles) {
            throw $this->deny((string) trans('validation.no_accepted_roles_defined'));
        }

        [$groupId, $source] = $this->resolveUserGroupId($request, $user, $explicitParameter, $routeGroupId);
        if ($groupId < 1) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        /** @var null|UserGroup $userGroup */
        $userGroup = UserGroup::query()->find($groupId);
        if (null === $userGroup) {
            $this->auditDenied($user, $groupId, $acceptedRoles, 'missing_group');
            if (AdministrationContext::SOURCE_ROUTE === $source) {
                throw new NotFoundHttpException();
            }

            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        $allowedRoleTitles = AdministrationRoleSet::allowedTitles($acceptedRoles);
        $membershipQuery    = GroupMembership::query()
            ->where('user_id', $user->id)
            ->where('user_group_id', $userGroup->id)
        ;
        if (!$membershipQuery->exists()) {
            $this->auditDenied($user, $groupId, $acceptedRoles, 'missing_membership');
            if (AdministrationContext::SOURCE_ROUTE === $source) {
                throw new NotFoundHttpException();
            }

            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        $membership        = (clone $membershipQuery)
            ->whereHas('userRole', static function ($query) use ($allowedRoleTitles): void {
                $query->whereIn('title', $allowedRoleTitles);
            })
            ->first()
        ;
        if (null === $membership) {
            $this->auditDenied($user, $groupId, $acceptedRoles, 'insufficient_role');

            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        $this->context->set($user, $userGroup, $acceptedRoles, $source);
        $request->attributes->set(AdministrationContext::REQUEST_ATTRIBUTE, $this->context);
        $request->attributes->set('userGroup', $userGroup);
        $request->attributes->set('user_group', $userGroup);
        $request->attributes->set('resolved_user_group', $userGroup);
        $request->attributes->set('firefly.resolved_user_group', $userGroup);
        event(new SharedAdministrationGroupSelected($user, $userGroup, (int) $user->user_group_id, static::class, $this->acceptedRoleValues($acceptedRoles)));

        return $this->context;
    }

    private function resolveUserGroupId(Request $request, User $user, bool $explicitParameter, ?int $routeGroupId): array
    {
        if (!$explicitParameter && null === $routeGroupId) {
            return [(int) $user->user_group_id, AdministrationContext::SOURCE_SELECTED_DEFAULT];
        }

        try {
            $parameterGroupId = ResolvesUserGroupParameter::resolveExplicit($request);
        } catch (ConflictHttpException|ValidationException) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        if (null !== $routeGroupId && null !== $parameterGroupId && $routeGroupId !== $parameterGroupId) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        if (null !== $parameterGroupId) {
            return [$parameterGroupId, AdministrationContext::SOURCE_EXPLICIT];
        }

        return [(int) $routeGroupId, AdministrationContext::SOURCE_ROUTE];
    }

    private function routeUserGroupId(Request $request): ?int
    {
        $route = $request->route();
        if (null === $route) {
            return null;
        }

        $parameter = $route->parameter('userGroup');
        if ($parameter instanceof UserGroup) {
            return $parameter->id;
        }
        if (is_int($parameter) && $parameter > 0) {
            return $parameter;
        }
        if (is_string($parameter) && 1 === preg_match('/^[1-9][0-9]*$/', $parameter)) {
            return (int) $parameter;
        }

        return null;
    }

    private function deny(string $message): AuthorizationException
    {
        return (new AuthorizationException($message))->withStatus(403);
    }

    private function auditDenied(User $user, int $groupId, array $acceptedRoles, string $reason): void
    {
        event(new SharedAdministrationAccessDenied($user, $groupId, (int) $user->user_group_id, static::class, $reason, $this->acceptedRoleValues($acceptedRoles)));
    }

    private function acceptedRoleValues(array $acceptedRoles): array
    {
        return array_values(array_map(static fn ($role): string => $role->value, $acceptedRoles));
    }
}
