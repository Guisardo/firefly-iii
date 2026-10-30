<?php

declare(strict_types=1);

namespace Tests\integration\Services\Internal\Update;

use Carbon\Carbon;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * The web bulk edit changes the category, budget and tags of transactions through the journal repository.
 * The repository hands the update service only the journal, never the transaction group, and the service
 * needs the group to know the administration it works in.
 *
 * @internal
 *
 * @coversNothing
 */
final class JournalUpdateWithoutGroupTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $group;
    private User $user;
    private UserGroup $userGroup;

    public function testBulkEditMovesTheGroupTimestamp(): void
    {
        $this->setClock(1);
        $this->repository()->updateCategory($this->journal(), 'Bulk category');

        self::assertSame($this->at(1), $this->updatedAt($this->group), 'bulk: group updated_at after a category update through the repository');
    }

    public function testCategoryUpdateThroughTheJournalRepository(): void
    {
        $this->setClock(1);
        $this->repository()->updateCategory($this->journal(), 'Bulk category');

        self::assertSame(1, DB::table('category_transaction_journal')->where('transaction_journal_id', $this->journal()->id)->count(), 'bulk: category rows of the journal');
    }

    public function testTagsUpdateThroughTheJournalRepository(): void
    {
        $this->setClock(1);
        $this->repository()->updateTags($this->journal(), ['bulk-tag']);

        self::assertSame(1, DB::table('tag_transaction_journal')->where('transaction_journal_id', $this->journal()->id)->count(), 'bulk: tag rows of the journal');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = $this->createAuthenticatedUser();
        $this->userGroup = UserGroup::query()->findOrFail($this->user->user_group_id);
        $this->setClock(0);
        $this->group     = $this->createWithdrawalInGroup($this->user, $this->userGroup);
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(int $hours): string
    {
        return $this->base($hours)->format('Y-m-d H:i:s');
    }

    private function base(int $hours): Carbon
    {
        return Carbon::create(2026, 6, 15, 10, 0, 0, config('app.timezone'))->addHours($hours);
    }

    private function journal(): TransactionJournal
    {
        return $this->group->transactionJournals()->firstOrFail();
    }

    private function repository(): JournalRepositoryInterface
    {
        $repository = app(JournalRepositoryInterface::class);
        $repository->setUser($this->user);

        return $repository;
    }

    private function setClock(int $hoursAfterStart): void
    {
        Carbon::setTestNow($this->base($hoursAfterStart));
    }

    private function updatedAt(TransactionGroup $group): string
    {
        return (string) DB::table('transaction_groups')->where('id', $group->id)->value('updated_at');
    }
}
