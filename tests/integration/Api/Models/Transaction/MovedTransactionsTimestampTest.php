<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\Transaction;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\UserGroup;
use FireflyIII\Services\Internal\Destroy\AccountDestroyService;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * Moving transactions to another account changes what clients see on the group,
 * so the group's updated_at has to move with them.
 *
 * @internal
 *
 * @coversNothing
 */
final class MovedTransactionsTimestampTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $affected;
    private Account $source;
    private Account $target;
    private TransactionGroup $unrelated;
    private User $user;
    private UserGroup $userGroup;

    public function testMovingTransactionsLeavesDeletedGroupsAlone(): void
    {
        $this->setClock(1);
        DB::table('transaction_groups')->where('id', $this->affected->id)->update([
            'deleted_at' => $this->at(1),
            'updated_at' => $this->at(1),
        ]);

        $this->setClock(2);
        $this->mover()->moveTransactions($this->source, $this->target);

        $row = DB::table('transaction_groups')->where('id', $this->affected->id)->first(['updated_at', 'deleted_at']);
        self::assertNotNull($row->deleted_at);
        self::assertSame($this->at(1), $row->updated_at);
    }

    public function testMovingTransactionsLeavesUnrelatedGroupsAlone(): void
    {
        $this->setClock(1);
        $this->mover()->moveTransactions($this->source, $this->target);

        self::assertSame($this->at(0), $this->updatedAt($this->unrelated));
    }

    public function testMovingTransactionsMovesTimestampOfAffectedGroups(): void
    {
        $this->setClock(1);
        $this->mover()->moveTransactions($this->source, $this->target);

        self::assertSame(0, DB::table('transactions')->where('account_id', $this->source->id)->count(), 'transactions left on the old account');
        self::assertSame(1, DB::table('transactions')->where('account_id', $this->target->id)->count(), 'transactions on the new account');
        self::assertSame($this->at(1), $this->updatedAt($this->affected));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = $this->createAuthenticatedUser();
        $this->userGroup = UserGroup::query()->findOrFail($this->user->user_group_id);
        $this->setClock(0);
        $this->affected  = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->unrelated = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->source    = $this->sourceAccountOf($this->affected);
        $this->target    = $this->createAccountInGroup($this->user, $this->userGroup, AccountTypeEnum::ASSET);
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(int $hours): string
    {
        return Carbon::create(2026, 1, 1, 10, 0, 0, config('app.timezone'))->addHours($hours)->format('Y-m-d H:i:s');
    }

    private function mover(): AccountDestroyService
    {
        return app(AccountDestroyService::class);
    }

    private function setClock(int $hoursAfterStart): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 10, 0, 0, config('app.timezone'))->addHours($hoursAfterStart));
    }

    private function sourceAccountOf(TransactionGroup $group): Account
    {
        $transaction = $group->transactionJournals()->firstOrFail()->transactions()->where('amount', '<', 0)->firstOrFail();

        return Account::query()->findOrFail($transaction->account_id);
    }

    private function updatedAt(TransactionGroup $group): string
    {
        return (string) DB::table('transaction_groups')->where('id', $group->id)->value('updated_at');
    }
}
