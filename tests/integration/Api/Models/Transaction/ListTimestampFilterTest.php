<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\Transaction;

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
 * The transaction list can be limited to what was created or changed in a window of time,
 * so a client only has to download what it does not have yet.
 *
 * Group A was created and last changed at the start. B was created 5 hours later and changed at +27 hours.
 * C was created at +30 hours and changed at +50 hours. The start is 15 June 2026 10:00 in the application timezone.
 *
 * @internal
 *
 * @coversNothing
 */
final class ListTimestampFilterTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $a;
    private TransactionGroup $b;
    private TransactionGroup $c;
    private User $user;
    private UserGroup $userGroup;

    public static function invalidValueProvider(): array
    {
        return [
            'a word'                  => [['updated_since' => 'yesterday']],
            'an impossible date'      => [['created_until' => '2026-13-45']],
            'a space instead of plus' => [['updated_since' => '2026-06-16T13:00:00 02:00']],
            'an hour out of range'    => [['created_since' => '2026-06-16T25:00:00']],
            'until before since'      => [['updated_since' => '2026-06-16T13:00:00+02:00', 'updated_until' => '2026-06-16T12:00:00+02:00']],
        ];
    }

    public function testCreatedSinceAndUntilAreInclusive(): void
    {
        self::assertSame($this->idsOf($this->b, $this->c), $this->listIds(['created_since' => $this->moment(5)]), 'filter: ids for created_since at the creation of B');
        self::assertSame($this->idsOf($this->a, $this->b), $this->listIds(['created_until' => $this->moment(5)]), 'filter: ids for created_until at the creation of B');
        self::assertSame($this->idsOf($this->b, $this->c), $this->listIds(['created_since' => $this->moment(5), 'created_until' => $this->moment(30)]), 'filter: ids for a created window');
    }

    public function testDateOnlyValuesCoverWholeDays(): void
    {
        // updated: A on 15 June 10:00, B on 16 June 13:00, C on 17 June 12:00.
        self::assertSame($this->idsOf($this->b, $this->c), $this->listIds(['updated_since' => '2026-06-16']), 'filter: ids for updated_since as a date');
        self::assertSame($this->idsOf($this->a, $this->b), $this->listIds(['updated_until' => '2026-06-16']), 'filter: ids for updated_until as a date');
        self::assertSame($this->idsOf($this->b), $this->listIds(['updated_since' => '2026-06-16', 'updated_until' => '2026-06-16']), 'filter: ids for a single day');
    }

    public function testFiltersAreCarriedIntoPagingLinks(): void
    {
        $since    = $this->moment(27);
        $response = $this->fetch(['updated_since' => $since, 'limit' => 1]);

        self::assertSame(2, $response->json('meta.pagination.total'), 'filter: total with filter and limit');
        $next     = urldecode((string) $response->json('links.next'));
        self::assertStringContainsString('updated_since='.$since, $next, 'filter: next link keeps the filter');
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValuesAreRejected(array $query): void
    {
        self::assertSame(422, $this->fetch($query)->status(), 'filter: status for '.json_encode($query));
    }

    public function testMembersWithoutReadAccessAreDenied(): void
    {
        $fixture = $this->createMultiGroupUserFixture(UserRoleEnum::MANAGE_TRANSACTIONS);
        Passport::actingAs($fixture['user']);

        $response = $this->getJson(route('api.v1.transactions.index', [
            'user_group_id' => $fixture['requested_group']->id,
            'updated_since' => '2026-06-16',
        ]));

        self::assertContains($response->status(), [401, 403], 'access: status for a member without the read role');
    }

    public function testNoFilterReturnsEverything(): void
    {
        self::assertSame($this->idsOf($this->a, $this->b, $this->c), $this->listIds([]), 'all groups without a filter');
    }

    public function testOffsetsAreConvertedToTheApplicationTimezone(): void
    {
        $boundary = $this->base(27);
        $values   = [
            'utc'              => $boundary->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'plus nine'        => $boundary->copy()->setTimezone('Asia/Tokyo')->toAtomString(),
            'compact offset'   => $boundary->copy()->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d\TH:i:sO'),
            'no offset (local)' => $boundary->format('Y-m-d\TH:i:s'),
        ];
        foreach ($values as $label => $value) {
            self::assertSame($this->idsOf($this->b, $this->c), $this->listIds(['updated_since' => $value]), sprintf('filter: ids for updated_since as %s (%s)', $label, $value));
        }
    }

    public function testUpdatedSinceIsInclusive(): void
    {
        self::assertSame($this->idsOf($this->b, $this->c), $this->listIds(['updated_since' => $this->moment(27)]), 'filter: ids for updated_since at the change of B');
        self::assertSame($this->idsOf($this->c), $this->listIds(['updated_since' => $this->base(27)->addSecond()->toAtomString()]), 'filter: ids for updated_since one second after B');
    }

    public function testUpdatedUntilIsInclusive(): void
    {
        self::assertSame($this->idsOf($this->a, $this->b), $this->listIds(['updated_until' => $this->moment(27)]), 'filter: ids for updated_until at the change of B');
        self::assertSame($this->idsOf($this->a), $this->listIds(['updated_until' => $this->base(27)->subSecond()->toAtomString()]), 'filter: ids for updated_until one second before B');
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
        $this->stamp($this->a, 0, 0);
        $this->stamp($this->b, 5, 27);
        $this->stamp($this->c, 30, 50);
    }

    private function base(int $hours): Carbon
    {
        return Carbon::create(2026, 6, 15, 10, 0, 0, config('app.timezone'))->addHours($hours);
    }

    private function idsOf(TransactionGroup ...$groups): array
    {
        $ids = array_map(static fn (TransactionGroup $group): int => (int) $group->id, $groups);
        sort($ids);

        return $ids;
    }

    private function listIds(array $query): array
    {
        $ids = array_map('intval', $this->fetch($query)->json('data.*.id') ?? []);
        sort($ids);

        return $ids;
    }

    private function moment(int $hours): string
    {
        return $this->base($hours)->toAtomString();
    }

    private function fetch(array $query): TestResponse
    {
        Passport::actingAs($this->user);

        return $this->getJson(route('api.v1.transactions.index', $query));
    }

    private function stamp(TransactionGroup $group, int $createdHours, int $updatedHours): void
    {
        DB::table('transaction_groups')->where('id', $group->id)->update([
            'created_at' => $this->base($createdHours)->format('Y-m-d H:i:s'),
            'updated_at' => $this->base($updatedHours)->format('Y-m-d H:i:s'),
        ]);
    }
}
