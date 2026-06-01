<?php

/*
 * UserGroupTransformer.php
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

namespace FireflyIII\Transformers;

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Http\SharedAdministration\AdministrationRoleSet;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Class UserGroupTransformer
 */
class UserGroupTransformer extends AbstractTransformer
{
    private array $inUse              = [];
    private array $capabilities       = [];
    private array $memberships        = [];
    private array $membershipsVisible = [];

    public function collectMetaData(Collection $objects): Collection
    {
        if (auth()->check()) {
            /** @var UserGroup $userGroup */
            foreach ($objects as $userGroup) {
                $this->collectUserGroupMetaData($userGroup);
            }
            $this->mergeMemberships();
        }

        return $objects;
    }

    /**
     * Transform the user group.
     */
    public function transform(UserGroup $userGroup): array
    {
        $currency = Amount::getPrimaryCurrencyByUserGroup($userGroup);
        $this->collectUserGroupMetaData($userGroup);

        return [
            'id'                              => $userGroup->id,
            'created_at'                      => $userGroup->created_at->toAtomString(),
            'updated_at'                      => $userGroup->updated_at->toAtomString(),
            'in_use'                          => $this->inUse[$userGroup->id] ?? false,
            'can_see_members'                 => $this->membershipsVisible[$userGroup->id] ?? false,
            'actor_roles'                     => $this->capabilities[$userGroup->id]['actor_roles'] ?? [],
            'can_use'                         => $this->capabilities[$userGroup->id]['can_use'] ?? false,
            'can_update'                      => $this->capabilities[$userGroup->id]['can_update'] ?? false,
            'can_manage_members'              => $this->capabilities[$userGroup->id]['can_manage_members'] ?? false,
            'can_manage_owner_roles'          => $this->capabilities[$userGroup->id]['can_manage_owner_roles'] ?? false,
            'can_destroy'                     => $this->capabilities[$userGroup->id]['can_destroy'] ?? false,
            'capabilities'                    => $this->capabilities[$userGroup->id] ?? $this->emptyCapabilities(),
            'title'                           => $userGroup->title,
            'primary_currency_id'             => (string) $currency->id,
            'primary_currency_name'           => $currency->name,
            'primary_currency_code'           => $currency->code,
            'primary_currency_symbol'         => $currency->symbol,
            'primary_currency_decimal_places' => $currency->decimal_places,
            'members'                         => array_values($this->memberships[$userGroup->id] ?? []),
        ];

        // if the user has a specific role in this group, then collect the memberships.
    }

    private function collectUserGroupMetaData(UserGroup $userGroup): void
    {
        $userGroupId = $userGroup->id;
        if (array_key_exists($userGroupId, $this->capabilities)) {
            return;
        }

        $this->inUse[$userGroupId]              = false;
        $this->membershipsVisible[$userGroupId] = false;
        $this->capabilities[$userGroupId]       = $this->emptyCapabilities();

        if (!auth()->check()) {
            return;
        }

        /** @var User $user */
        $user                                   = auth()->user();
        if (true === $user->blocked) {
            return;
        }

        $this->inUse[$userGroupId]              = $user->user_group_id === $userGroupId;
        $groupMemberships                       = GroupMembership::query()
            ->where('user_group_id', $userGroupId)
            ->with(['user', 'userRole'])
            ->get()
        ;
        $currentUserRoles                       = [];

        /** @var GroupMembership $groupMembership */
        foreach ($groupMemberships as $groupMembership) {
            if ($groupMembership->user_id === $user->id) {
                $currentUserRoles[] = $groupMembership->userRole->title;
            }
        }

        $canUse                                 = [] !== $currentUserRoles;
        $canUpdate                              = $this->hasCapability($currentUserRoles, [UserRoleEnum::FULL]);
        $canManageOwnerRoles                    = $this->hasCapability($currentUserRoles, [UserRoleEnum::OWNER]);
        $canViewMembers                         = $this->hasCapability($currentUserRoles, [UserRoleEnum::VIEW_MEMBERSHIPS]);

        $this->capabilities[$userGroupId]       = [
            'actor_roles'               => array_values(array_unique($currentUserRoles)),
            'can_use'                   => $canUse,
            'can_update'                => $canUpdate,
            'can_manage_members'        => $canUpdate,
            'can_manage_owner_roles'    => $canManageOwnerRoles,
            'can_destroy'               => $canManageOwnerRoles,
            'can_read'                  => $this->hasCapability($currentUserRoles, [UserRoleEnum::READ_ONLY]),
            'can_manage_transactions'   => $this->hasCapability($currentUserRoles, [UserRoleEnum::MANAGE_TRANSACTIONS]),
            'can_manage_administration' => $canUpdate,
            'can_view_members'          => $canViewMembers,
            'can_delete'                => $canManageOwnerRoles,
        ];
        $this->membershipsVisible[$userGroupId] = $this->capabilities[$userGroupId]['can_view_members'];
        if (!$this->membershipsVisible[$userGroupId]) {
            return;
        }

        /** @var GroupMembership $groupMembership */
        foreach ($groupMemberships as $groupMembership) {
            $mail                                      = $groupMembership->user->email;
            $this->memberships[$userGroupId][$mail] ??= [
                'user_id'    => (string) $groupMembership->user_id,
                'user_email' => $mail,
                'you'        => $groupMembership->user_id === $user->id,
                'roles'      => [],
            ];
            $this->memberships[$userGroupId][$mail]['roles'][] = $groupMembership->userRole->title;
        }
    }

    private function emptyCapabilities(): array
    {
        return [
            'actor_roles'               => [],
            'can_use'                   => false,
            'can_update'                => false,
            'can_manage_members'        => false,
            'can_manage_owner_roles'    => false,
            'can_destroy'               => false,
            'can_read'                  => false,
            'can_manage_transactions'   => false,
            'can_manage_administration' => false,
            'can_view_members'          => false,
            'can_delete'                => false,
        ];
    }

    private function hasCapability(array $roleTitles, array $acceptedRoles): bool
    {
        $allowedRoleTitles = AdministrationRoleSet::allowedTitles($acceptedRoles);

        return [] !== array_intersect($roleTitles, $allowedRoleTitles);
    }

    private function mergeMemberships(): void
    {
        $new               = [];
        foreach ($this->memberships as $groupId => $members) {
            $new[$groupId] ??= [];

            foreach ($members as $member) {
                if (array_key_exists('roles', $member)) {
                    $new[$groupId][$member['user_email']] = $member;

                    continue;
                }

                $mail                            = $member['user_email'];
                $new[$groupId][$mail] ??= [
                    'user_id'    => (string) $member['user_id'],
                    'user_email' => $member['user_email'],
                    'you'        => $member['you'],
                    'roles'      => [],
                ];
                $new[$groupId][$mail]['roles'][] = $member['role'];
            }
        }
        $this->memberships = $new;
    }
}
