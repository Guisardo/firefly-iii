<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\Transaction;

use Carbon\Carbon;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * Every change a client can see on a transaction must move the group's updated_at,
 * because that is the value the list endpoint reports and filters on.
 *
 * @internal
 *
 * @coversNothing
 */
final class GroupTimestampPropagationTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $group;
    private int $journalId;
    private User $user;
    private UserGroup $userGroup;

    public static function putFieldsProvider(): array
    {
        return [
            'description' => [['description' => 'changed description']],
            'amount'      => [['amount' => '20.00']],
            'category'    => [['category_name' => 'Groceries']],
            'tags'        => [['tags' => ['alpha', 'beta']]],
            'notes'       => [['notes' => 'a note']],
        ];
    }

    public static function serviceFieldsProvider(): array
    {
        return [
            'description' => [['description' => 'service description']],
            'notes'       => [['notes' => 'service note']],
            'category'    => [['category_name' => 'Service category']],
            'tags'        => [['tags' => ['service-tag']]],
            'reconciled'  => [['reconciled' => true]],
        ];
    }

    #[DataProvider('putFieldsProvider')]
    public function testApiPutMovesGroupTimestamp(array $fields): void
    {
        $this->setClock(1);
        $this->putJournal($fields);

        $this->assertGroupMoved(1);
        self::assertSame($this->expected(0), (string) DB::table('transaction_groups')->where('id', $this->group->id)->value('created_at'));
    }

    public function testApiDeleteOfGroupKeepsTombstoneTimestamps(): void
    {
        $this->setClock(2);
        Passport::actingAs($this->user);
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $this->group->id]))->assertNoContent();

        $row = DB::table('transaction_groups')->where('id', $this->group->id)->first(['updated_at', 'deleted_at']);
        self::assertSame($this->expected(2), $row->deleted_at);
        self::assertSame($row->deleted_at, $row->updated_at);
    }

    public function testDeletingOneJournalOfASplitGroupMovesGroupTimestamp(): void
    {
        $second = $this->addSecondJournal();

        $this->setClock(1);
        Passport::actingAs($this->user);
        $this->deleteJson(route('api.v1.transaction-journals.delete', ['tj' => $second->id]))->assertNoContent();

        $this->assertSoftDeleted('transaction_journals', ['id' => $second->id]);
        self::assertNull(TransactionGroup::query()->find($this->group->id)?->deleted_at);
        $this->assertGroupMoved(1);
    }

    #[DataProvider('serviceFieldsProvider')]
    public function testJournalUpdateServiceMovesGroupTimestamp(array $data): void
    {
        $this->setClock(1);

        /** @var JournalUpdateService $service */
        $service = app(JournalUpdateService::class);
        $service->setTransactionGroup($this->group);
        $service->setTransactionJournal($this->journal());
        $service->setData($data);
        $service->update();

        $this->assertGroupMoved(1);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = $this->createAuthenticatedUser();
        $this->userGroup = UserGroup::query()->findOrFail($this->user->user_group_id);
        $this->setClock(0);
        $this->group     = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->journalId = (int) $this->group->transactionJournals()->firstOrFail()->id;
        Passport::actingAs($this->user);
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addSecondJournal(): TransactionJournal
    {
        $first   = $this->journal();
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
        $journal->transactionGroup()->associate($this->group);
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

        return $journal;
    }

    private function assertGroupMoved(int $hours): void
    {
        self::assertSame($this->expected($hours), $this->groupUpdatedAt(), 'updated_at of the group in the database');

        Passport::actingAs($this->user);
        $response = $this->getJson(route('api.v1.transactions.index'));
        $response->assertOk();
        self::assertSame($this->expectedAtom($hours), $response->json('data.0.attributes.updated_at'), 'updated_at reported by the list endpoint');
    }

    private function base(int $hours): Carbon
    {
        return Carbon::create(2026, 1, 1, 10, 0, 0, config('app.timezone'))->addHours($hours);
    }

    private function expected(int $hours): string
    {
        return $this->base($hours)->format('Y-m-d H:i:s');
    }

    private function expectedAtom(int $hours): string
    {
        return $this->base($hours)->toAtomString();
    }

    private function groupUpdatedAt(): string
    {
        return (string) DB::table('transaction_groups')->where('id', $this->group->id)->value('updated_at');
    }

    private function journal(): TransactionJournal
    {
        return TransactionJournal::query()->findOrFail($this->journalId);
    }

    private function putJournal(array $fields): void
    {
        Passport::actingAs($this->user);
        $response = $this->putJson(route('api.v1.transactions.update', ['transactionGroup' => $this->group->id]), [
            'transactions' => [['transaction_journal_id' => $this->journalId] + $fields],
        ]);
        $response->assertOk();
    }

    private function setClock(int $hoursAfterStart): void
    {
        Carbon::setTestNow($this->base($hoursAfterStart));
    }
}
