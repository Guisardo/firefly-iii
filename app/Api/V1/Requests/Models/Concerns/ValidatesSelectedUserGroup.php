<?php

/*
 * ValidatesSelectedUserGroup.php
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

namespace FireflyIII\Api\V1\Requests\Models\Concerns;

use Closure;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\UserGroup;
use FireflyIII\Rules\BelongsUser;
use FireflyIII\Rules\BelongsUserGroup;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Contracts\Validation\ValidationRule;

use function Safe\json_encode;

trait ValidatesSelectedUserGroup
{
    private ?UserGroup $selectedUserGroup = null;

    protected function authorizeSelectedUserGroup(array $acceptedRoles): bool
    {
        if (!$this->hasExplicitUserGroupId()) {
            return true;
        }
        if (!auth()->check()) {
            return false;
        }

        /** @var User $user */
        $user    = auth()->user();
        if ($user->blocked) {
            return false;
        }

        $groupId = (int) $this->get('user_group_id');
        if ($groupId < 1) {
            return false;
        }

        /** @var null|UserGroup $userGroup */
        $userGroup = UserGroup::find($groupId);
        if (null === $userGroup) {
            return false;
        }

        $membershipCount = $user->groupMemberships()->where('user_group_id', $userGroup->id)->count();
        if (0 === $membershipCount) {
            return false;
        }

        /** @var UserRoleEnum $role */
        foreach ($acceptedRoles as $role) {
            if ($user->hasRoleInGroupOrOwner($userGroup, $role)) {
                $this->selectedUserGroup = $userGroup;

                return true;
            }
        }

        return false;
    }

    protected function selectedUserGroupId(): ?int
    {
        return $this->selectedUserGroup?->id;
    }

    protected function userGroupIdRule(): array
    {
        return ['numeric', 'exists:user_groups,id', 'nullable'];
    }

    protected function belongsUserOrSelectedUserGroup(): ValidationRule|Closure
    {
        if ($this->selectedUserGroup instanceof UserGroup) {
            return new BelongsUserGroup($this->selectedUserGroup);
        }

        if ($this->hasExplicitUserGroupId()) {
            return static function (string $attribute, mixed $value, Closure $fail): void {
                $fail('validation.belongs_user_or_user_group')->translate();
            };
        }

        return new BelongsUser();
    }

    protected function uniqueAccountNameForUserOrSelectedGroup(?Account $account = null, ?string $type = null): string|Closure
    {
        if (!$this->selectedUserGroup instanceof UserGroup) {
            if ($account instanceof Account) {
                return sprintf('uniqueAccountForUser:%d', $account->id);
            }

            return 'uniqueAccountForUser';
        }

        return function (string $attribute, mixed $value, Closure $fail) use ($account, $type): void {
            if (is_array($value)) {
                $fail('validation.unique_account_for_user')->translate();

                return;
            }

            $accountTypeIds = $this->accountTypeIdsForValidation($type, $account);
            if (0 === count($accountTypeIds)) {
                return;
            }

            $query          = Account::query()
                ->where('user_group_id', $this->selectedUserGroup->id)
                ->whereNull('deleted_at')
                ->where('name', (string) $value)
                ->whereIn('account_type_id', $accountTypeIds)
            ;

            if ($account instanceof Account) {
                $query->where('id', '!=', $account->id);
            }

            if ($query->exists()) {
                $fail('validation.unique_account_for_user')->translate();
            }
        };
    }

    protected function uniqueIbanForUserOrSelectedGroup(?Account $account = null, ?string $expectedType = null): ValidationRule|Closure
    {
        if (!$this->selectedUserGroup instanceof UserGroup) {
            return new \FireflyIII\Rules\UniqueIban($account, $expectedType);
        }

        return function (string $attribute, mixed $value, Closure $fail) use ($account, $expectedType): void {
            $expectedTypes = $this->expectedAccountTypes($expectedType);
            if (0 === count($expectedTypes)) {
                return;
            }
            if (is_array($value)) {
                $fail((string) trans('validation.unique_iban_for_user'));

                return;
            }

            $iban      = Steam::filterSpaces((string) $value);
            $maxCounts = $this->ibanMaxOccurrences($expectedTypes);

            foreach ($maxCounts as $type => $max) {
                $types = 'liabilities' === $type ? [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value] : [$type];
                $count = $this->countSelectedGroupAccounts($types, ['accounts.iban', '=', $iban], $account);
                if ($count > $max) {
                    $fail((string) trans('validation.unique_iban_for_user'));

                    return;
                }
            }
        };
    }

    protected function uniqueAccountNumberForUserOrSelectedGroup(?Account $account = null, ?string $expectedType = null): ValidationRule|Closure
    {
        if (!$this->selectedUserGroup instanceof UserGroup) {
            return new \FireflyIII\Rules\UniqueAccountNumber($account, $expectedType);
        }

        return function (string $attribute, mixed $value, Closure $fail) use ($account, $expectedType): void {
            $expectedType = $this->normalAccountType($expectedType);
            if (null === $expectedType) {
                return;
            }
            if (is_array($value)) {
                $fail('validation.generic_invalid')->translate();

                return;
            }

            $maxCounts = [AccountTypeEnum::ASSET->value => 0, AccountTypeEnum::EXPENSE->value => 0, AccountTypeEnum::REVENUE->value => 0];
            if (AccountTypeEnum::EXPENSE->value === $expectedType) {
                $maxCounts[AccountTypeEnum::REVENUE->value] = 1;
            }
            if (AccountTypeEnum::REVENUE->value === $expectedType) {
                $maxCounts[AccountTypeEnum::EXPENSE->value] = 1;
            }

            foreach ($maxCounts as $type => $max) {
                $count = $this->countSelectedGroupAccountNumbers($type, (string) $value, $account);
                if ($count > $max) {
                    $fail('validation.unique_account_number_for_user')->translate();

                    return;
                }
            }
        };
    }

    protected function hasExplicitUserGroupId(): bool
    {
        return $this->has('user_group_id');
    }

    private function accountTypeIdsForValidation(?string $type, ?Account $account): array
    {
        if (null !== $type && '' !== $type) {
            $search = config('firefly.accountTypeByIdentifier.'.$type);
            if (is_array($search)) {
                return AccountType::query()->whereIn('type', $search)->pluck('id')->toArray();
            }
        }

        if ($account instanceof Account) {
            return [$account->account_type_id];
        }

        return [];
    }

    private function countSelectedGroupAccountNumbers(string $type, string $accountNumber, ?Account $account): int
    {
        $query = AccountMeta::leftJoin('accounts', 'accounts.id', '=', 'account_meta.account_id')
            ->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->where('accounts.user_group_id', $this->selectedUserGroup->id)
            ->where('account_types.type', $type)
            ->where('account_meta.name', '=', 'account_number')
            ->where('account_meta.data', json_encode($accountNumber))
        ;

        if ($account instanceof Account) {
            $query->where('accounts.id', '!=', $account->id);
        }

        return $query->count();
    }

    private function countSelectedGroupAccounts(array $types, array $where, ?Account $account): int
    {
        $query = Account::query()
            ->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->where('accounts.user_group_id', $this->selectedUserGroup->id)
            ->whereIn('account_types.type', $types)
            ->where($where[0], $where[1], $where[2])
        ;

        if ($account instanceof Account) {
            $query->where('accounts.id', '!=', $account->id);
        }

        return $query->count();
    }

    private function expectedAccountTypes(?string $expectedType): array
    {
        $normal = $this->normalAccountType($expectedType);
        if (null === $normal) {
            return [];
        }
        if ('liabilities' === $expectedType) {
            return [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];
        }

        return [$normal];
    }

    private function ibanMaxOccurrences(array $expectedTypes): array
    {
        $maxCounts = [
            AccountTypeEnum::ASSET->value   => 0,
            AccountTypeEnum::EXPENSE->value => 0,
            AccountTypeEnum::REVENUE->value => 0,
            'liabilities'                   => 0,
        ];

        if (in_array(AccountTypeEnum::EXPENSE->value, $expectedTypes, true)) {
            $maxCounts[AccountTypeEnum::REVENUE->value] = 1;
        }
        if (in_array(AccountTypeEnum::REVENUE->value, $expectedTypes, true)) {
            $maxCounts[AccountTypeEnum::EXPENSE->value] = 1;
        }

        return $maxCounts;
    }

    private function normalAccountType(?string $expectedType): ?string
    {
        if (null === $expectedType) {
            return null;
        }

        return match ($expectedType) {
            'expense'      => AccountTypeEnum::EXPENSE->value,
            'revenue'      => AccountTypeEnum::REVENUE->value,
            'asset'        => AccountTypeEnum::ASSET->value,
            'liabilities'  => 'liabilities',
            default        => $expectedType,
        };
    }
}
