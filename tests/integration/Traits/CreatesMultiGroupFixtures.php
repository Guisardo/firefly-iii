<?php

/*
 * CreatesMultiGroupFixtures.php
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

namespace Tests\integration\Traits;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;

trait CreatesMultiGroupFixtures
{
    protected function createMultiGroupUserFixture(UserRoleEnum $requestedRole = UserRoleEnum::OWNER): array
    {
        $activeGroup    = $this->createUserGroup('Active administration');
        $requestedGroup = $this->createUserGroup('Requested administration');
        $unrelatedGroup = $this->createUserGroup('Unrelated administration');
        $user           = User::create([
            'email'         => sprintf('multi-group-%s@example.test', strtolower($requestedRole->name)),
            'password'      => 'password',
            'user_group_id' => $activeGroup->id,
        ]);

        $this->createGroupMembership($user, $activeGroup, UserRoleEnum::OWNER);
        $this->createGroupMembership($user, $requestedGroup, $requestedRole);

        return [
            'user'            => $user,
            'active_group'    => $activeGroup,
            'requested_group' => $requestedGroup,
            'unrelated_group' => $unrelatedGroup,
        ];
    }

    protected function createMultiGroupRoleMembers(UserGroup $userGroup): array
    {
        return [
            UserRoleEnum::OWNER->value               => $this->createUserInGroup($userGroup, UserRoleEnum::OWNER),
            UserRoleEnum::FULL->value                => $this->createUserInGroup($userGroup, UserRoleEnum::FULL),
            UserRoleEnum::MANAGE_TRANSACTIONS->value => $this->createUserInGroup($userGroup, UserRoleEnum::MANAGE_TRANSACTIONS),
            UserRoleEnum::READ_ONLY->value           => $this->createUserInGroup($userGroup, UserRoleEnum::READ_ONLY),
        ];
    }

    protected function createAccountInGroup(User $user, UserGroup $userGroup, AccountTypeEnum $type = AccountTypeEnum::ASSET, ?string $name = null): Account
    {
        $data = ['user_group_id' => $userGroup->id];
        if (null !== $name) {
            $data['name'] = $name;
        }

        return Account::factory()
            ->for($user)
            ->withType($type)
            ->create($data)
        ;
    }

    protected function createWithdrawalInGroup(User $user, UserGroup $userGroup): TransactionGroup
    {
        $source      = $this->createAccountInGroup($user, $userGroup, AccountTypeEnum::ASSET);
        $destination = $this->createAccountInGroup($user, $userGroup, AccountTypeEnum::EXPENSE);
        $currency    = TransactionCurrency::query()->firstOrFail();
        $type        = TransactionType::query()->where('type', TransactionTypeEnum::WITHDRAWAL->value)->firstOrFail();
        $group       = TransactionGroup::create([
            'user_id'       => $user->id,
            'user_group_id' => $userGroup->id,
            'title'         => 'Multi-group withdrawal',
        ]);
        $journal     = new TransactionJournal([
            'user_id'                 => $user->id,
            'user_group_id'           => $userGroup->id,
            'transaction_type_id'     => $type->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Multi-group withdrawal',
            'date'                    => Carbon::create(2026, 5, 31, 12, 0, 0),
            'date_tz'                 => 'UTC',
            'completed'               => false,
            'order'                   => 0,
            'tag_count'               => 0,
        ]);
        $journal->transactionGroup()->associate($group);
        $journal->save();

        Transaction::create([
            'account_id'              => $source->id,
            'transaction_journal_id'  => $journal->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Multi-group source',
            'amount'                  => '-12.34',
            'native_amount'           => '-12.34',
            'reconciled'              => false,
        ]);
        Transaction::create([
            'account_id'              => $destination->id,
            'transaction_journal_id'  => $journal->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Multi-group destination',
            'amount'                  => '12.34',
            'native_amount'           => '12.34',
            'reconciled'              => false,
        ]);

        return $group->refresh();
    }

    protected function createUserInGroup(UserGroup $userGroup, UserRoleEnum $role): User
    {
        $user = User::create([
            'email'         => sprintf('%s-%d@example.test', $role->value, $userGroup->id),
            'password'      => 'password',
            'user_group_id' => $userGroup->id,
        ]);
        $this->createGroupMembership($user, $userGroup, $role);

        return $user;
    }

    protected function createGroupMembership(User $user, UserGroup $userGroup, UserRoleEnum $role): GroupMembership
    {
        $userRole = UserRole::query()->where('title', $role->value)->firstOrFail();

        return GroupMembership::create([
            'user_id'       => $user->id,
            'user_group_id' => $userGroup->id,
            'user_role_id'  => $userRole->id,
        ]);
    }

    protected function usePrimaryCurrencyForGroup(UserGroup $userGroup, string $code): TransactionCurrency
    {
        $currency = $this->findOrCreateCurrencyByCode($code);

        foreach ($userGroup->currencies()->get() as $existing) {
            $userGroup->currencies()->updateExistingPivot($existing->id, ['group_default' => false]);
        }
        $userGroup->currencies()->syncWithoutDetaching([$currency->id => ['group_default' => true]]);

        return $currency;
    }

    private function createUserGroup(string $title): UserGroup
    {
        return UserGroup::create(['title' => sprintf('%s %s', $title, substr(sha1($title.microtime(true).random_int(1, PHP_INT_MAX)), 0, 8))]);
    }

    private function findOrCreateCurrencyByCode(string $code): TransactionCurrency
    {
        $currency = TransactionCurrency::query()->where('code', $code)->first();
        if (null !== $currency) {
            return $currency;
        }

        $defaults = [
            'EUR' => ['name' => 'Euro', 'symbol' => 'EUR', 'decimal_places' => 2, 'enabled' => true],
            'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'enabled' => true],
        ];

        return TransactionCurrency::create(array_merge(['code' => $code], $defaults[$code] ?? [
            'name'           => $code,
            'symbol'         => $code,
            'decimal_places' => 2,
            'enabled'        => true,
        ]));
    }
}
