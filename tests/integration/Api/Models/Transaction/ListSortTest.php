<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\Transaction;

use Carbon\Carbon;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * The transaction list can be sorted by id, created_at and updated_at of the group, in the database,
 * so paging and filtering keep working and ties never move rows around between pages.
 *
 * Groups a to d were created in that order (so their ids go up). Their moments, in hours after
 * 15 June 2026 10:00 (application timezone):
 *   a: created 0, updated 0     b: created 5, updated 27     c: created 2, updated 50     d: created 5, updated 27
 * b and d tie on both moments. The journals have dates that make the default order b, d, a, c.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListSortTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $a;
    private TransactionGroup $b;
    private TransactionGroup $c;
    private TransactionGroup $d;
    private User $user;
    private UserGroup $userGroup;

    public static function invalidSortProvider(): array
    {
        return [
            'an unknown field' => ['name'],
            'too short'        => ['x'],
            'a trailing comma' => ['id,'],
        ];
    }

    public static function orderProvider(): array
    {
        return [
            'id ascending'                   => ['id', ['a', 'b', 'c', 'd']],
            'id descending'                  => ['-id', ['d', 'c', 'b', 'a']],
            'updated ascending'              => ['updated_at', ['a', 'b', 'd', 'c']],
            'updated descending'             => ['-updated_at', ['c', 'd', 'b', 'a']],
            'created ascending'              => ['created_at', ['a', 'c', 'b', 'd']],
            'created descending'             => ['-created_at', ['d', 'b', 'c', 'a']],
            'two keys, tie ends ascending'   => ['-updated_at,created_at', ['c', 'b', 'd', 'a']],
            'two keys, tie ends descending'  => ['updated_at,-created_at', ['a', 'd', 'b', 'c']],
        ];
    }

    public function testDefaultOrderIsUnchangedWithoutSort(): void
    {
        $this->makeFour();

        self::assertSame($this->idsOf('b', 'd', 'a', 'c'), $this->listIds([]), 'default order without sort');
    }

    #[DataProvider('invalidSortProvider')]
    public function testInvalidSortIsRejected(string $sort): void
    {
        $this->makeFour();

        self::assertSame(422, $this->fetch(['sort' => $sort])->status(), 'sort: status for sort='.$sort);
    }

    public function testSortCarriedIntoPagingLinks(): void
    {
        $this->makeFour();
        $response = $this->fetch(['sort' => '-updated_at', 'limit' => 2]);

        $next     = urldecode((string) $response->json('links.next'));
        self::assertStringContainsString('sort=-updated_at', $next, 'sort: next link keeps the sort');
    }

    public function testSortCombinesWithFilters(): void
    {
        $this->makeFour();

        self::assertSame(
            $this->idsOf('c', 'd', 'b'),
            $this->listIds(['updated_since' => $this->base(27)->toAtomString(), 'sort' => '-updated_at']),
            'sort: ids for updated_since with sort=-updated_at'
        );
    }

    public function testSortKeepsSplitGroupsTogether(): void
    {
        $a = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $s = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $c = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->addSecondJournal($s);
        $this->stamp($a, 0, 0, '2026-05-31 13:00:00');
        $this->stamp($s, 0, 10, '2026-05-31 14:00:00');
        $this->stamp($c, 0, 50, '2026-05-31 15:00:00');

        $first = $this->fetch(['sort' => 'updated_at', 'limit' => 3, 'page' => 1]);
        self::assertSame(4, $first->json('meta.pagination.total'), 'sort: rows in total');
        self::assertSame([(int) $a->id, (int) $s->id], array_map('intval', $first->json('data.*.id') ?? []), 'sort: first page holds whole groups');
        $second = $this->fetch(['sort' => 'updated_at', 'limit' => 3, 'page' => 2]);
        self::assertSame([(int) $c->id], array_map('intval', $second->json('data.*.id') ?? []), 'sort: second page');
    }

    #[DataProvider('orderProvider')]
    public function testSortOrdersGroups(string $sort, array $expected): void
    {
        $this->makeFour();

        self::assertSame($this->idsOf(...$expected), $this->listIds(['sort' => $sort]), 'sort: ids for sort='.$sort);
    }

    public function testSortedPagesFollowEachOther(): void
    {
        $this->makeFour();

        $first  = $this->fetch(['sort' => '-updated_at', 'limit' => 2, 'page' => 1]);
        $second = $this->fetch(['sort' => '-updated_at', 'limit' => 2, 'page' => 2]);
        self::assertSame($this->idsOf('c', 'd'), array_map('intval', $first->json('data.*.id') ?? []), 'sort: first page');
        self::assertSame($this->idsOf('b', 'a'), array_map('intval', $second->json('data.*.id') ?? []), 'sort: second page');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = $this->createAuthenticatedUser();
        $this->userGroup = UserGroup::query()->findOrFail($this->user->user_group_id);
    }

    private function addSecondJournal(TransactionGroup $group): void
    {
        $first   = $group->transactionJournals()->firstOrFail();
        $journal = new TransactionJournal([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->userGroup->id,
            'transaction_type_id'     => $first->transaction_type_id,
            'transaction_currency_id' => $first->transaction_currency_id,
            'description'             => 'Second journal',
            'date'                    => Carbon::create(2026, 5, 31, 12, 0, 0),
            'date_tz'                 => 'UTC',
            'completed'               => false,
            'order'                   => 1,
            'tag_count'               => 0,
        ]);
        $journal->transactionGroup()->associate($group);
        $journal->save();

        foreach ($first->transactions as $transaction) {
            Transaction::create([
                'account_id'              => $transaction->account_id,
                'transaction_journal_id'  => $journal->id,
                'transaction_currency_id' => $transaction->transaction_currency_id,
                'description'             => 'Second journal leg',
                'amount'                  => $transaction->amount,
                'native_amount'           => $transaction->native_amount,
                'reconciled'              => false,
            ]);
        }
    }

    private function base(int $hours): Carbon
    {
        return Carbon::create(2026, 6, 15, 10, 0, 0, config('app.timezone'))->addHours($hours);
    }

    private function fetch(array $query): TestResponse
    {
        Passport::actingAs($this->user);

        return $this->getJson(route('api.v1.transactions.index', $query));
    }

    private function idsOf(string ...$names): array
    {
        return array_map(fn (string $name): int => (int) $this->{$name}->id, $names);
    }

    private function listIds(array $query): array
    {
        return array_map('intval', $this->fetch($query)->json('data.*.id') ?? []);
    }

    private function makeFour(): void
    {
        $this->a = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->b = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->c = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->d = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->stamp($this->a, 0, 0, '2026-05-31 13:00:00');
        $this->stamp($this->b, 5, 27, '2026-05-31 15:00:00');
        $this->stamp($this->c, 2, 50, '2026-05-31 12:00:00');
        $this->stamp($this->d, 5, 27, '2026-05-31 14:00:00');
    }

    private function stamp(TransactionGroup $group, int $createdHours, int $updatedHours, string $journalDate): void
    {
        DB::table('transaction_groups')->where('id', $group->id)->update([
            'created_at' => $this->base($createdHours)->format('Y-m-d H:i:s'),
            'updated_at' => $this->base($updatedHours)->format('Y-m-d H:i:s'),
        ]);
        DB::table('transaction_journals')->where('transaction_group_id', $group->id)->update(['date' => $journalDate]);
    }
}
