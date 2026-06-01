<?php

/*
 * TransactionJournalScopeControllerTest.php
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

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\DestroyController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\ListController
 * @covers \FireflyIII\Api\V1\Controllers\Models\Transaction\ShowController
 * @covers \FireflyIII\Models\TransactionJournal
 */
final class TransactionJournalScopeControllerTest extends TestCase
{
    use CreatesMultiGroupFixtures;
    use RefreshDatabase;

    private array $fixture;

    public function testShowJournalUsesExplicitRequestedGroupForSameGroupMember(): void
    {
        $owner   = $this->createUserInGroup($this->fixture['requested_group'], UserRoleEnum::OWNER);
        $group   = $this->createWithdrawalInGroup($owner, $this->fixture['requested_group']);
        $journal = $this->journal($group);

        Passport::actingAs($this->fixture['user']);
        $response = $this->getJson(route('api.v1.transaction-journals.show', [
            'tj'            => $journal->id,
            'user_group_id' => $this->fixture['requested_group']->id,
        ]));

        $response->assertOk();
        self::assertSame((string) $group->id, $response->json('data.id'));
        self::assertSame($this->fixture['active_group']->id, $this->fixture['user']->refresh()->user_group_id);
    }

    public function testJournalLinksUseExplicitRequestedGroupForSameGroupMember(): void
    {
        $owner       = $this->createUserInGroup($this->fixture['requested_group'], UserRoleEnum::OWNER);
        $source      = $this->journal($this->createWithdrawalInGroup($owner, $this->fixture['requested_group']));
        $dest        = $this->journal($this->createWithdrawalInGroup($owner, $this->fixture['requested_group']));
        $journalLink = $this->createJournalLink($source, $dest);

        Passport::actingAs($this->fixture['user']);
        $response = $this->getJson(route('api.v1.transaction-journals.transaction-links', [
            'tj'            => $source->id,
            'user_group_id' => $this->fixture['requested_group']->id,
        ]));

        $response->assertOk();
        $response->assertJson(['meta' => ['pagination' => ['total' => 1]]]);
        self::assertSame((string) $journalLink->id, $response->json('data.0.id'));
        self::assertSame($this->fixture['active_group']->id, $this->fixture['user']->refresh()->user_group_id);
    }

    public function testDestroyJournalUsesExplicitRequestedGroupForSameGroupMember(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $owner   = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $journal = $this->journal($this->createWithdrawalInGroup($owner, $fixture['requested_group']));

        Passport::actingAs($fixture['user']);
        $response = $this->deleteJson(route('api.v1.transaction-journals.delete', [
            'tj'            => $journal->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertNoContent();
        $this->assertSoftDeleted('transaction_journals', ['id' => $journal->id]);
        self::assertSame($fixture['active_group']->id, $fixture['user']->refresh()->user_group_id);
    }

    #[DataProvider('journalRouteProvider')]
    public function testJournalRoutesFailClosedWhenJournalIsOutsideRequestedGroup(string $method, string $routeName): void
    {
        $fixture = 'DELETE' === $method ? $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS) : $this->fixture;
        $group   = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);
        $journal = $this->journal($group);
        if ('api.v1.transaction-journals.transaction-links' === $routeName) {
            $this->createJournalLink($journal, $this->journal($this->createWithdrawalInGroup($fixture['user'], $fixture['active_group'])));
        }

        Passport::actingAs($fixture['user']);
        $response = $this->json($method, route($routeName, [
            'tj'            => $journal->id,
            'user_group_id' => $fixture['requested_group']->id,
        ]));

        $response->assertNotFound();
        $this->assertDatabaseHas('transaction_journals', [
            'id'         => $journal->id,
            'deleted_at' => null,
        ]);
    }

    public function testNoUserGroupIdUsesSelectedDefaultBindingAcrossMembers(): void
    {
        $owner   = $this->createUserInGroup($this->fixture['active_group'], UserRoleEnum::OWNER);
        $group   = $this->createWithdrawalInGroup($owner, $this->fixture['active_group']);
        $journal = $this->journal($group);

        Passport::actingAs($this->fixture['user']);
        $response = $this->getJson(route('api.v1.transaction-journals.show', ['tj' => $journal->id]));

        $response->assertOk();
        self::assertSame((string) $group->id, $response->json('data.id'));
    }

    public function testNonMemberExplicitGroupIsDeniedBeforeJournalBinding(): void
    {
        $journal = $this->journal($this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['active_group']));

        Passport::actingAs($this->fixture['user']);
        $response = $this->getJson(route('api.v1.transaction-journals.show', [
            'tj'            => $journal->id,
            'user_group_id' => $this->fixture['unrelated_group']->id,
        ]));

        $response->assertUnauthorized();
    }

    public function testBlockedUserExplicitGroupIsDeniedBeforeJournalBinding(): void
    {
        $journal                         = $this->journal($this->createWithdrawalInGroup($this->fixture['user'], $this->fixture['requested_group']));
        $this->fixture['user']->blocked = true;
        $this->fixture['user']->save();

        Passport::actingAs($this->fixture['user']->refresh());
        $response = $this->getJson(route('api.v1.transaction-journals.show', [
            'tj'            => $journal->id,
            'user_group_id' => $this->fixture['requested_group']->id,
        ]));

        $response->assertUnauthorized();
    }

    public function testReadOnlyUserCannotDestroyJournalInExplicitGroup(): void
    {
        $owner   = $this->createUserInGroup($this->fixture['requested_group'], UserRoleEnum::OWNER);
        $journal = $this->journal($this->createWithdrawalInGroup($owner, $this->fixture['requested_group']));

        Passport::actingAs($this->fixture['user']);
        $response = $this->deleteJson(route('api.v1.transaction-journals.delete', [
            'tj'            => $journal->id,
            'user_group_id' => $this->fixture['requested_group']->id,
        ]));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('transaction_journals', [
            'id'         => $journal->id,
            'deleted_at' => null,
        ]);
    }

    public static function journalRouteProvider(): array
    {
        return [
            'show'   => ['GET', 'api.v1.transaction-journals.show'],
            'links'  => ['GET', 'api.v1.transaction-journals.transaction-links'],
            'delete' => ['DELETE', 'api.v1.transaction-journals.delete'],
        ];
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
    }

    private function createJournalLink(TransactionJournal $source, TransactionJournal $destination): TransactionJournalLink
    {
        $linkType = LinkType::query()->firstOrCreate(
            ['name' => 'Related'],
            ['inward' => 'relates to', 'outward' => 'relates to', 'editable' => false]
        );

        $link                 = new TransactionJournalLink();
        $link->link_type_id   = $linkType->id;
        $link->source_id      = $source->id;
        $link->destination_id = $destination->id;
        $link->save();

        return $link;
    }

    private function journal(TransactionGroup $group): TransactionJournal
    {
        return $group->transactionJournals()->firstOrFail();
    }
}
