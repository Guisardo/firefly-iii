<?php

/*
 * UpdateMembershipRequest.php
 * Copyright (c) 2026 james@firefly-iii.org.
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Models\UserGroup;

use FireflyIII\Api\V1\Requests\Models\UserGroup\Concerns\AuthorizesUserGroupRequests;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use FireflyIII\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends FormRequest
{
    use AuthorizesUserGroupRequests;
    use ConvertsDataTypes;

    protected array $acceptedRoles = [UserRoleEnum::FULL];

    public function authorize(): bool
    {
        $userGroup = $this->route('userGroup');
        if (!$userGroup instanceof UserGroup) {
            return false;
        }

        if (!$this->authorizeRouteUserGroup($this->acceptedRoles)) {
            return false;
        }

        /** @var User $actor */
        $actor          = auth()->user();
        $target = $this->targetUser();
        if (null !== $target && true === (bool) $target->blocked) {
            return false;
        }

        $requestedRoles = $this->requestedRoles();
        $targetIsOwner  = null !== $target && $this->hasSpecificGroupRole($target, $userGroup, UserRoleEnum::OWNER);
        $changesOwner   = in_array(UserRoleEnum::OWNER->value, $requestedRoles, true) || ($targetIsOwner && !in_array(UserRoleEnum::OWNER->value, $requestedRoles, true));

        if ($changesOwner && !$this->actingUserHasRoleInGroup($userGroup, UserRoleEnum::OWNER)) {
            return false;
        }

        return true;
    }

    public function getData(): array
    {
        return [
            'id'    => $this->convertInteger('id'),
            'email' => $this->convertString('email'),
            'roles' => $this->get('roles') ?? [],
        ];
    }

    public function rules(): array
    {
        $roles = array_map(static fn (UserRoleEnum $role): string => $role->value, UserRoleEnum::cases());

        return [
            'id'      => ['nullable', 'integer', 'exists:users,id', 'required_without:email'],
            'email'   => ['nullable', 'email', 'exists:users,email', 'required_without:id'],
            'roles'   => ['present', 'array'],
            'roles.*' => ['string', Rule::in($roles)],
        ];
    }

    private function requestedRoles(): array
    {
        $roles = $this->get('roles');

        return is_array($roles) ? $roles : [];
    }

    private function targetUser(): ?User
    {
        $id = (int) $this->get('id');
        if ($id > 0) {
            return User::find($id);
        }

        $email = (string) $this->get('email');
        if ('' !== $email) {
            return User::whereEmail($email)->first();
        }

        return null;
    }

    private function hasSpecificGroupRole(User $user, UserGroup $userGroup, UserRoleEnum $role): bool
    {
        return $user->groupMemberships()
            ->where('user_group_id', $userGroup->id)
            ->whereHas('userRole', static fn ($query) => $query->where('title', $role->value))
            ->exists()
        ;
    }
}
