<?php

/*
 * UpdateMembershipRequest.php
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

namespace FireflyIII\Api\V1\Requests\Models\UserGroup;

use FireflyIII\Api\V1\Requests\Models\UserGroup\Concerns\AuthorizesUserGroupRequests;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Support\Request\ConvertsDataTypes;
use FireflyIII\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMembershipRequest extends FormRequest
{
    use AuthorizesUserGroupRequests;
    use ConvertsDataTypes;

    protected array $acceptedRoles = [UserRoleEnum::FULL];

    public function authorize(): bool
    {
        if (!$this->authorizeRouteUserGroup($this->acceptedRoles)) {
            return false;
        }

        if (!$this->changesOwnerRole()) {
            return true;
        }

        $userGroup = $this->route()?->parameter('userGroup');

        if (!$userGroup instanceof UserGroup) {
            return false;
        }

        return $this->actingUserHasRoleInGroup($userGroup, UserRoleEnum::OWNER);
    }

    public function getData(): array
    {
        $data = [
            'email' => $this->convertString('email'),
            'roles' => $this->arrayFromValue($this->get('roles')) ?? [],
        ];
        if ($this->has('id')) {
            $data['id'] = $this->convertInteger('id');
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'id'      => ['required_without:email', 'nullable', 'integer', 'exists:users,id'],
            'email'   => ['required_without:id', 'nullable', 'email', 'exists:users,email'],
            'roles'   => ['present', 'array'],
            'roles.*' => sprintf('in:%s', implode(',', array_map(static fn (UserRoleEnum $role): string => $role->value, UserRoleEnum::cases()))),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $userGroup = $this->route()?->parameter('userGroup');
            $target    = $this->targetUser();

            if (!$userGroup instanceof UserGroup || !$target instanceof User) {
                return;
            }

            if ($this->changesOwnerRole() && !$this->actingUserIsOwner($userGroup)) {
                $validator->errors()->add('roles', 'Only owners can grant or revoke the owner role.');
            }
            if ($this->wouldRemoveLastOwner($target, $userGroup)) {
                $validator->errors()->add('roles', 'The last owner in this user group must keep the owner role.');
            }
        });
    }

    private function changesOwnerRole(): bool
    {
        $userGroup = $this->route()?->parameter('userGroup');
        $target    = $this->targetUser();

        if (!$userGroup instanceof UserGroup || !$target instanceof User) {
            return in_array(UserRoleEnum::OWNER->value, $this->arrayFromValue($this->get('roles')) ?? [], true);
        }

        $hasOwner  = $this->userHasRoleInGroup($target, $userGroup, UserRoleEnum::OWNER);
        $getsOwner = in_array(UserRoleEnum::OWNER->value, $this->arrayFromValue($this->get('roles')) ?? [], true);

        return $hasOwner !== $getsOwner;
    }

    private function actingUserIsOwner(UserGroup $userGroup): bool
    {
        return $this->actingUserHasRoleInGroup($userGroup, UserRoleEnum::OWNER);
    }

    private function wouldRemoveLastOwner(User $target, UserGroup $userGroup): bool
    {
        if (!$this->userHasRoleInGroup($target, $userGroup, UserRoleEnum::OWNER)) {
            return false;
        }
        if (in_array(UserRoleEnum::OWNER->value, $this->arrayFromValue($this->get('roles')) ?? [], true)) {
            return false;
        }

        /** @var null|UserRole $ownerRole */
        $ownerRole = UserRole::whereTitle(UserRoleEnum::OWNER->value)->first();
        if (null === $ownerRole) {
            return true;
        }

        return 0 === $userGroup->groupMemberships()
            ->where('user_role_id', $ownerRole->id)
            ->where('user_id', '!=', $target->id)
            ->count()
        ;
    }

    private function targetUser(): ?User
    {
        if ($this->has('id')) {
            return User::find($this->convertInteger('id'));
        }

        $email = $this->convertString('email');
        if ('' !== $email) {
            return User::whereEmail($email)->first();
        }

        return null;
    }

    private function userHasRoleInGroup(User $user, UserGroup $userGroup, UserRoleEnum $role): bool
    {
        /** @var null|UserRole $userRole */
        $userRole = UserRole::whereTitle($role->value)->first();
        if (null === $userRole) {
            return false;
        }

        return $user->groupMemberships()
            ->where('user_group_id', $userGroup->id)
            ->where('user_role_id', $userRole->id)
            ->exists()
        ;
    }
}
