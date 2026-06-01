<?php

/*
 * WriteScopeControllerTest.php
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

namespace Tests\integration\Api\Models\Transaction;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\DestroyController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\StoreController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\UpdateController
 */
final class WriteScopeControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private array $fixture;
    private User $user;

    public function testStoreUsesExplicitRequestedGroupWithoutChangingActiveGroup(): void
    {
        $source      = $this->createAccountInGroup($this->user, $this->fixture['requested_group'], AccountTypeEnum::ASSET);
        $destination = $this->createAccountInGroup($this->user, $this->fixture['requested_group'], AccountTypeEnum::EXPENSE);

        Passport::actingAs($this->user);
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'user_group_id' => $this->fixture['requested_group']->id,
            'source_id'     => $source->id,
            'destination_id' => $destination->id,
        ]));

        $response->assertOk();
        $transactionGroup = TransactionGroup::query()
            ->where('user_id', $this->user->id)
            ->where('user_group_id', $this->fixture['requested_group']->id)
            ->latest('id')
            ->firstOrFail()
        ;
        $this->assertSame((string) $transactionGroup->id, $response->json('data.id'));
        $this->assertSame('USD', $response->json('data.attributes.transactions.0.primary_currency_code'));
        $this->assertSame($this->fixture['requested_group']->id, $transactionGroup->user_group_id);
        $this->assertSame($this->fixture['requested_group']->id, $transactionGroup->transactionJournals()->firstOrFail()->user_group_id);
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testStoreWithoutUserGroupIdUsesSelectedDefaultAccountsAcrossMembers(): void
    {
        $owner       = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $source      = $this->createAccountInGroup($owner, $this->fixture['active_group'], AccountTypeEnum::ASSET);
        $destination = $this->createAccountInGroup($owner, $this->fixture['active_group'], AccountTypeEnum::EXPENSE);

        Passport::actingAs($this->user);
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'source_id'     => $source->id,
            'destination_id' => $destination->id,
        ]));

        $response->assertOk();
        $transactionGroup = TransactionGroup::query()
            ->where('user_id', $this->user->id)
            ->where('user_group_id', $this->fixture['active_group']->id)
            ->latest('id')
            ->firstOrFail()
        ;
        $this->assertSame((string) $transactionGroup->id, $response->json('data.id'));
        $this->assertSame($this->fixture['active_group']->id, $transactionGroup->transactionJournals()->firstOrFail()->user_group_id);
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testStoreRejectsCrossGroupAccountWhenExplicitGroupIsRequested(): void
    {
        $source      = $this->createAccountInGroup($this->user, $this->fixture['unrelated_group'], AccountTypeEnum::ASSET);
        $destination = $this->createAccountInGroup($this->user, $this->fixture['requested_group'], AccountTypeEnum::EXPENSE);

        Passport::actingAs($this->user);
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'user_group_id' => $this->fixture['requested_group']->id,
            'source_id'     => $source->id,
            'destination_id' => $destination->id,
        ]));

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('transaction_groups', ['title' => 'Explicit requested-group transaction']);
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testUpdateUsesExplicitRequestedGroupWithoutChangingActiveGroup(): void
    {
        $transactionGroup = $this->createWithdrawalInGroup($this->user, $this->fixture['requested_group']);

        Passport::actingAs($this->user);
        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $transactionGroup->id]), [
            'user_group_id' => $this->fixture['requested_group']->id,
            'group_title'   => 'Updated requested transaction',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('transaction_groups', [
            'id'            => $transactionGroup->id,
            'user_group_id' => $this->fixture['requested_group']->id,
            'title'         => 'Updated requested transaction',
        ]);
        $this->assertSame('USD', $response->json('data.attributes.transactions.0.primary_currency_code'));
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testUpdateWithoutUserGroupIdUsesSelectedDefaultAcrossMembers(): void
    {
        $owner            = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $transactionGroup = $this->createWithdrawalInGroup($owner, $this->fixture['active_group']);

        Passport::actingAs($this->user);
        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $transactionGroup->id]), [
            'group_title' => 'Updated active transaction',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('transaction_groups', [
            'id'            => $transactionGroup->id,
            'user_group_id' => $this->fixture['active_group']->id,
            'title'         => 'Updated active transaction',
        ]);
        $this->assertSame('EUR', $response->json('data.attributes.transactions.0.primary_currency_code'));
    }

    public function testStoreWithoutUserGroupIdReturnsActiveDefaultPrimaryCurrency(): void
    {
        $source      = $this->createAccountInGroup($this->user, $this->fixture['active_group'], AccountTypeEnum::ASSET);
        $destination = $this->createAccountInGroup($this->user, $this->fixture['active_group'], AccountTypeEnum::EXPENSE);

        Passport::actingAs($this->user);
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'source_id'     => $source->id,
            'destination_id' => $destination->id,
        ]));

        $response->assertOk();
        $transactionGroup = TransactionGroup::query()
            ->where('user_id', $this->user->id)
            ->where('user_group_id', $this->fixture['active_group']->id)
            ->latest('id')
            ->firstOrFail()
        ;
        $this->assertSame((string) $transactionGroup->id, $response->json('data.id'));
        $this->assertSame('EUR', $response->json('data.attributes.transactions.0.primary_currency_code'));
    }

    public function testUpdateRouteAndRequestGroupMismatchFailsClosed(): void
    {
        $transactionGroup = $this->createWithdrawalInGroup($this->user, $this->fixture['unrelated_group']);

        Passport::actingAs($this->user);
        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $transactionGroup->id]), [
            'user_group_id' => $this->fixture['requested_group']->id,
            'group_title'   => 'Should not update',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transaction_groups', [
            'id'    => $transactionGroup->id,
            'title' => 'Should not update',
        ]);
    }

    public function testDestroyUsesExplicitRequestedGroupWithoutChangingActiveGroup(): void
    {
        $transactionGroup = $this->createWithdrawalInGroup($this->user, $this->fixture['requested_group']);
        $journalId        = $transactionGroup->transactionJournals()->firstOrFail()->id;

        Passport::actingAs($this->user);
        $response = $this->deleteJson(route('api.v1.transactions.delete', [
            'transactionGroup' => $transactionGroup->id,
            'user_group_id'    => $this->fixture['requested_group']->id,
        ]));

        $response->assertNoContent();
        $this->assertSoftDeleted('transaction_groups', ['id' => $transactionGroup->id]);
        $this->assertSoftDeleted('transaction_journals', ['id' => $journalId]);
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testDestroyWithoutUserGroupIdUsesSelectedDefaultAcrossMembers(): void
    {
        $owner            = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $transactionGroup = $this->createWithdrawalInGroup($owner, $this->fixture['active_group']);
        $journalId        = $transactionGroup->transactionJournals()->firstOrFail()->id;

        Passport::actingAs($this->user);
        $response = $this->deleteJson(route('api.v1.transactions.delete', [
            'transactionGroup' => $transactionGroup->id,
        ]));

        $response->assertNoContent();
        $this->assertSoftDeleted('transaction_groups', ['id' => $transactionGroup->id]);
        $this->assertSoftDeleted('transaction_journals', ['id' => $journalId]);
        $this->assertSame($this->fixture['active_group']->id, $this->user->refresh()->user_group_id);
    }

    public function testDestroyJournalRouteAndRequestGroupMismatchFailsClosed(): void
    {
        $transactionGroup = $this->createWithdrawalInGroup($this->user, $this->fixture['unrelated_group']);
        $journal          = $transactionGroup->transactionJournals()->firstOrFail();

        Passport::actingAs($this->user);
        $response = $this->deleteJson(route('api.v1.transaction-journals.delete', [
            'tj'            => $journal->id,
            'user_group_id' => $this->fixture['requested_group']->id,
        ]));

        $response->assertNotFound();
        $this->assertDatabaseHas('transaction_journals', [
            'id'            => $journal->id,
            'user_group_id' => $this->fixture['unrelated_group']->id,
            'deleted_at'    => null,
        ]);
    }

    public function testReadOnlyUserCannotWriteTransactions(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $source  = $this->createAccountInGroup($fixture['user'], $fixture['requested_group'], AccountTypeEnum::ASSET);
        $dest    = $this->createAccountInGroup($fixture['user'], $fixture['requested_group'], AccountTypeEnum::EXPENSE);
        $group   = $this->createWithdrawalInGroup($fixture['user'], $fixture['requested_group']);

        Passport::actingAs($fixture['user']);
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'user_group_id' => $fixture['requested_group']->id,
            'source_id'     => $source->id,
            'destination_id' => $dest->id,
        ]));

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('transaction_groups', ['title' => 'Explicit requested-group transaction']);
        $this->assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);

        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $group->id]), [
            'user_group_id' => $fixture['requested_group']->id,
            'group_title'   => 'Read-only changed title',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('transaction_groups', [
            'id'    => $group->id,
            'title' => 'Read-only changed title',
        ]);
    }

    public function testReadOnlyUserCannotWriteSelectedDefaultTransactions(): void
    {
        $fixture = $this->createMultiGroupUserFixture();
        $fixture['user']->groupMemberships()->where('user_group_id', $fixture['active_group']->id)->delete();
        $this->createGroupMembership($fixture['user'], $fixture['active_group'], UserRoleEnum::READ_ONLY);
        $source  = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::ASSET);
        $dest    = $this->createAccountInGroup($fixture['user'], $fixture['active_group'], AccountTypeEnum::EXPENSE);

        Passport::actingAs($fixture['user']->refresh());
        $response = $this->postJson(route('api.v1.transactions.store'), $this->payload([
            'source_id'     => $source->id,
            'destination_id' => $dest->id,
        ]));

        $response->assertUnauthorized();
        $this->assertDatabaseMissing('transaction_groups', ['title' => 'Explicit requested-group transaction']);
        $this->assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $this->usePrimaryCurrencyForGroup($this->fixture['active_group'], 'EUR');
        $this->usePrimaryCurrencyForGroup($this->fixture['requested_group'], 'USD');
        $this->user    = $this->fixture['user'];
    }

    private function payload(array $overrides = []): array
    {
        $payload = [
            'error_if_duplicate_hash' => false,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'transactions'            => [[
                'type'           => 'withdrawal',
                'date'           => '2026-05-31T12:00:00+00:00',
                'amount'         => '12.34',
                'description'    => 'Explicit requested-group transaction',
                'source_id'      => null,
                'destination_id' => null,
            ]],
        ];

        $transactionKeys = ['source_id', 'destination_id'];

        foreach ($overrides as $key => $value) {
            if (in_array($key, $transactionKeys, true)) {
                $payload['transactions'][0][$key] = $value;
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }
}
