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

use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

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
        if (!$this->hasExplicitUserGroupId($request)) {
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

        $groupId = $this->requestedUserGroupId($request);
        if (null === $groupId) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        /** @var null|UserGroup $userGroup */
        $userGroup = UserGroup::query()->find($groupId);
        if (null === $userGroup) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        $allowedRoleTitles = AdministrationRoleSet::allowedTitles($acceptedRoles);
        $membership        = GroupMembership::query()
            ->where('user_id', $user->id)
            ->where('user_group_id', $userGroup->id)
            ->whereHas('userRole', static function ($query) use ($allowedRoleTitles): void {
                $query->whereIn('title', $allowedRoleTitles);
            })
            ->first()
        ;
        if (null === $membership) {
            throw $this->deny((string) trans('validation.belongs_user_or_user_group'));
        }

        $this->context->set($user, $userGroup, $acceptedRoles);
        $request->attributes->set(AdministrationContext::REQUEST_ATTRIBUTE, $this->context);
        $request->attributes->set('userGroup', $userGroup);
        $request->attributes->set('user_group', $userGroup);
        $request->attributes->set('resolved_user_group', $userGroup);
        $request->attributes->set('firefly.resolved_user_group', $userGroup);

        return $this->context;
    }

    private function hasExplicitUserGroupId(Request $request): bool
    {
        if ($request->query->has('user_group_id') || $request->request->has('user_group_id')) {
            return true;
        }

        return $request->isJson() && $request->json()->has('user_group_id');
    }

    private function requestedUserGroupId(Request $request): ?int
    {
        if ($request->query->has('user_group_id')) {
            return $this->normalizeUserGroupId($request->query->all()['user_group_id'] ?? null);
        }
        if ($request->isJson() && $request->json()->has('user_group_id')) {
            return $this->normalizeUserGroupId($request->json('user_group_id'));
        }
        if ($request->request->has('user_group_id')) {
            return $this->normalizeUserGroupId($request->request->all()['user_group_id'] ?? null);
        }

        return null;
    }

    private function normalizeUserGroupId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
            return null;
        }
        if (strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function deny(string $message): AuthorizationException
    {
        return (new AuthorizationException($message))->withStatus(403);
    }
}
