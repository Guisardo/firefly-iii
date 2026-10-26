<?php

declare(strict_types=1);

namespace Tests\integration\Api\Data;

use Carbon\Carbon;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\TransactionGroup;
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
 * A client that keeps a copy of the transactions asks what was deleted since its last visit.
 *
 * Moments are hours after 15 June 2026 10:00 (application timezone). Group a is not deleted.
 * b and d were deleted at +5 (a tie), c at +30, and e at +50 through the API.
 *
 * @internal
 *
 * @coversNothing
 */
final class DeletedItemsTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $a;
    private TransactionGroup $b;
    private TransactionGroup $c;
    private TransactionGroup $d;
    private TransactionGroup $e;
    private User $user;
    private UserGroup $userGroup;

    public static function invalidValueProvider(): array
    {
        return [
            'a word'                      => [['since' => 'yesterday']],
            'an impossible date'          => [['since' => '2026-13-45']],
            'an unknown type'             => [['types' => 'accounts']],
            'a known and an unknown type' => [['types' => 'transactions,accounts']],
        ];
    }

    public function testDateOnlySinceCoversWholeDays(): void
    {
        self::assertSame($this->idsOf('c', 'e'), $this->listIds(['since' => '2026-06-16']), 'deleted: ids for since as a date');
        self::assertSame($this->idsOf('e'), $this->listIds(['since' => '2026-06-17']), 'deleted: ids for since on the day of e');
        self::assertSame([], $this->listIds(['since' => '2026-06-18']), 'deleted: ids for since after every deletion');
    }

    public function testDefaultScopeIsTheSelectedAdministration(): void
    {
        [$fixture, $inActive] = $this->accessFixture();

        self::assertSame([(int) $inActive->id], $this->listIds([], $fixture['user']), 'deleted: ids without a group');
    }

    public function testExplicitAdministrationListsItsOwnDeletions(): void
    {
        [$fixture, , $inRequested] = $this->accessFixture();

        self::assertSame([(int) $inRequested->id], $this->listIds(['user_group_id' => $fixture['requested_group']->id], $fixture['user']), 'deleted: ids with an explicit group');
    }

    public function testItemsCarryTheEntityAndTheMomentOfDeletion(): void
    {
        $response = $this->fetch(['limit' => 1]);

        self::assertSame(200, $response->status(), 'deleted: status for limit=1');
        self::assertSame((string) $this->b->id, $response->json('data.0.id'), 'deleted: id of the first item');
        self::assertSame('transactions', $response->json('data.0.attributes.entity'), 'deleted: entity of the first item');
        self::assertSame($this->moment(5), $response->json('data.0.attributes.deleted_at'), 'deleted: moment of deletion of the first item');
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValuesAreRejected(array $query): void
    {
        self::assertSame(422, $this->fetch($query)->status(), 'deleted: status for '.json_encode($query));
    }

    public function testListsDeletedGroupsOldestFirst(): void
    {
        $response = $this->fetch([]);

        self::assertSame(200, $response->status(), 'deleted: status without parameters');
        self::assertSame($this->idsOf('b', 'd', 'c', 'e'), $this->idsFrom($response), 'deleted: ids, oldest deletion first, ties by id');
        self::assertSame(4, $response->json('meta.pagination.total'), 'deleted: total without parameters');
    }

    public function testMembersWithoutReadAccessAreDenied(): void
    {
        $fixture  = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        $response = $this->fetch(['user_group_id' => $fixture['requested_group']->id], $fixture['user']);

        self::assertContains($response->status(), [401, 403], 'deleted: status for a member without the read role');
    }

    public function testNothingDeletedSinceGivesAnEmptyList(): void
    {
        $response = $this->fetch(['since' => '2027-01-01']);

        self::assertSame(200, $response->status(), 'deleted: status for since in the future');
        self::assertSame([], $this->idsFrom($response), 'deleted: ids for since in the future');
        self::assertSame(0, $response->json('meta.pagination.total'), 'deleted: total for since in the future');
    }

    public function testPagesFollowEachOther(): void
    {
        self::assertSame($this->idsOf('b', 'd'), $this->listIds(['limit' => 2, 'page' => 1]), 'deleted: first page');
        self::assertSame($this->idsOf('c', 'e'), $this->listIds(['limit' => 2, 'page' => 2]), 'deleted: second page');
        self::assertSame([], $this->listIds(['limit' => 2, 'page' => 3]), 'deleted: third page');
    }

    public function testPagingLinksCarryTheParameters(): void
    {
        $response = $this->fetch(['limit' => 1, 'since' => '2026-06-15', 'types' => 'transactions']);

        self::assertSame(200, $response->status(), 'deleted: status for a limited list');
        self::assertSame(4, $response->json('meta.pagination.total'), 'deleted: total for a limited list');
        $next = urldecode((string) $response->json('links.next'));
        self::assertStringContainsString('since=2026-06-15', $next, 'deleted: next link keeps since');
        self::assertStringContainsString('types=transactions', $next, 'deleted: next link keeps types');
        self::assertStringContainsString('limit=1', $next, 'deleted: next link keeps the limit');
    }

    public function testSinceIsInclusive(): void
    {
        self::assertSame($this->idsOf('b', 'd', 'c', 'e'), $this->listIds(['since' => $this->moment(5)]), 'deleted: ids for since at the tie');
        self::assertSame($this->idsOf('c', 'e'), $this->listIds(['since' => $this->moment(30)]), 'deleted: ids for since at the deletion of c');
        self::assertSame($this->idsOf('e'), $this->listIds(['since' => $this->base(30)->addSecond()->toAtomString()]), 'deleted: ids for since one second after c');
    }

    public function testUnrelatedAdministrationIsDenied(): void
    {
        $fixture  = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $response = $this->fetch(['user_group_id' => $fixture['unrelated_group']->id], $fixture['user']);

        self::assertContains($response->status(), [401, 403], 'deleted: status for a group the user is not a member of');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = $this->createAuthenticatedUser();
        $this->userGroup = UserGroup::query()->findOrFail($this->user->user_group_id);
        $this->a         = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->b         = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->c         = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->d         = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->e         = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->softDelete($this->b, 5);
        $this->softDelete($this->d, 5);
        $this->softDelete($this->c, 30);

        // e goes through the API, so the list follows a real deletion.
        Carbon::setTestNow($this->base(50));
        Passport::actingAs($this->user);
        $this->deleteJson(route('api.v1.transactions.delete', ['transactionGroup' => $this->e->id]))->assertNoContent();
        Carbon::setTestNow();
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function accessFixture(): array
    {
        $fixture          = $this->createMultiGroupUserFixture(UserRoleEnum::READ_ONLY);
        $requestedCreator = $this->createUserInGroup($fixture['requested_group'], UserRoleEnum::OWNER);
        $inActive         = $this->createWithdrawalInGroup($fixture['user'], $fixture['active_group']);
        $inRequested      = $this->createWithdrawalInGroup($requestedCreator, $fixture['requested_group']);
        $this->createWithdrawalInGroup($requestedCreator, $fixture['requested_group']);
        $this->softDelete($inActive, 5);
        $this->softDelete($inRequested, 6);

        return [$fixture, $inActive, $inRequested];
    }

    private function base(int $hours): Carbon
    {
        return Carbon::create(2026, 6, 15, 10, 0, 0, config('app.timezone'))->addHours($hours);
    }

    private function fetch(array $query, ?User $actor = null): TestResponse
    {
        Passport::actingAs($actor ?? $this->user);

        return $this->getJson('/api/v1/data/deleted?'.http_build_query($query));
    }

    private function idsFrom(TestResponse $response): array
    {
        return array_map('intval', $response->json('data.*.id') ?? []);
    }

    private function idsOf(string ...$names): array
    {
        return array_map(fn (string $name): int => (int) $this->{$name}->id, $names);
    }

    private function listIds(array $query, ?User $actor = null): array
    {
        $response = $this->fetch($query, $actor);
        self::assertSame(200, $response->status(), 'deleted: status for '.json_encode($query));

        return $this->idsFrom($response);
    }

    private function moment(int $hours): string
    {
        return $this->base($hours)->toAtomString();
    }

    private function softDelete(TransactionGroup $group, int $hours): void
    {
        $moment = $this->base($hours)->format('Y-m-d H:i:s');
        DB::table('transaction_groups')->where('id', $group->id)->update(['deleted_at' => $moment, 'updated_at' => $moment]);
    }
}
