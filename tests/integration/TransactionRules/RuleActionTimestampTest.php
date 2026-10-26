<?php

declare(strict_types=1);

namespace Tests\integration\TransactionRules;

use Carbon\Carbon;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\UserGroup;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\integration\TestCase;
use Tests\integration\Traits\CreatesMultiGroupFixtures;

/**
 * Rule actions write through plain queries, so the rule engine has to move the group's updated_at
 * for every action that changed a transaction.
 *
 * @internal
 *
 * @coversNothing
 */
final class RuleActionTimestampTest extends TestCase
{
    use CreatesMultiGroupFixtures;

    private TransactionGroup $affected;
    private int $affectedJournalId;
    private TransactionGroup $other;
    private User $user;
    private UserGroup $userGroup;

    public static function actionProvider(): array
    {
        return [
            'set_category' => ['set_category', 'Groceries', 'category_transaction_journal'],
            'add_tag'      => ['add_tag', 'shopping', 'tag_transaction_journal'],
        ];
    }

    public function testRuleActionLeavesOtherGroupsAlone(): void
    {
        $this->setClock(1);
        $this->fireRuleOn($this->affectedJournalId, 'set_category', 'Groceries');

        self::assertSame($this->at(0), $this->updatedAt($this->other), 'group updated_at of a group the rule did not process');
    }

    #[DataProvider('actionProvider')]
    public function testRuleActionMovesGroupTimestamp(string $actionType, string $actionValue, string $pivotTable): void
    {
        $this->setClock(1);
        $this->fireRuleOn($this->affectedJournalId, $actionType, $actionValue);

        self::assertSame(1, DB::table($pivotTable)->where('transaction_journal_id', $this->affectedJournalId)->count(), 'effect of the rule action');
        self::assertSame($this->at(1), $this->updatedAt($this->affected), 'group updated_at after rule action');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user              = $this->createAuthenticatedUser();
        $this->userGroup         = UserGroup::query()->findOrFail($this->user->user_group_id);
        $this->setClock(0);
        $this->affected          = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->other             = $this->createWithdrawalInGroup($this->user, $this->userGroup);
        $this->affectedJournalId = (int) $this->affected->transactionJournals()->firstOrFail()->id;
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

    private function fireRuleOn(int $journalId, string $actionType, string $actionValue): void
    {
        $ruleGroup = RuleGroup::create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->userGroup->id,
            'stop_processing' => false,
            'order'           => 1,
            'title'           => 'Timestamp rule group',
            'description'     => 'Timestamp rule group',
            'active'          => true,
        ]);
        $rule      = Rule::create([
            'rule_group_id' => $ruleGroup->id,
            'user_id'       => $this->user->id,
            'user_group_id' => $this->userGroup->id,
            'order'         => 1,
            'active'        => true,
            'strict'        => true,
            'title'         => 'Timestamp rule',
            'description'   => 'Timestamp rule',
        ]);
        RuleTrigger::create([
            'rule_id'         => $rule->id,
            'trigger_type'    => 'description_is',
            'trigger_value'   => 'Multi-group withdrawal',
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false,
        ]);
        RuleAction::create([
            'rule_id'         => $rule->id,
            'action_type'     => $actionType,
            'action_value'    => $actionValue,
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false,
        ]);

        /** @var RuleEngineInterface $engine */
        $engine    = app(RuleEngineInterface::class);
        $engine->setUser($this->user);
        $engine->setRules(new Collection([$rule]));
        $engine->addOperator(['type' => 'journal_id', 'value' => $journalId]);
        $engine->fire();
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
