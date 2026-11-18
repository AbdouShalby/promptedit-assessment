<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditLedger;
use App\Generations\ApplyProviderSnapshot;
use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** Prepaid credit: reserve the maximum at submit, settle to the actual cost at completion, never twice. */
class BalanceTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    // The default form (medium, 1024x1024, "a red fox") can cost up to $0.086275.
    private const MAX_ESTIMATE_NANO = 86_275_000;

    private const PACK_NANO = 5_000_000_000;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakePricing();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    private function buyCredits(?string $key = null): void
    {
        $this->actingAs($this->user)
            ->post('/credits', ['idempotency_key' => $key ?? (string) Str::uuid()])
            ->assertRedirect('/generations');
    }

    private function submit(): Generation
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->user)->post('/generations', [
            'uuid' => $uuid, 'prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium',
        ])->assertSessionHasNoErrors();

        return Generation::where('uuid', $uuid)->sole();
    }

    private function balance(): int
    {
        return $this->user->fresh()->balance_nano;
    }

    /** The invariant behind everything here: the cached balance equals the sum of the ledger. */
    private function assertBalanceMatchesLedger(): void
    {
        $this->assertSame((int) CreditEntry::where('user_id', $this->user->id)->sum('amount_nano'), $this->balance());
    }

    public function test_new_users_start_with_no_credit_and_cannot_generate(): void
    {
        $this->actingAs($this->user)->post('/generations', [
            'uuid' => (string) Str::uuid(), 'prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium',
        ])->assertSessionHasErrors(['balance' => 'Not enough credit: this generation can cost up to $0.0863 and you have $0.0000. Buy credits to continue.']);

        $this->assertSame(0, Generation::count());
        $this->assertSame(0, CreditEntry::count());
        Queue::assertNothingPushed();
    }

    public function test_buying_credits_adds_one_pack_and_a_repeated_request_adds_nothing(): void
    {
        $key = (string) Str::uuid();

        $this->buyCredits($key);
        $this->buyCredits($key);   // double click

        $this->assertSame(self::PACK_NANO, $this->balance());
        $this->assertSame(1, CreditEntry::where('type', CreditEntry::PURCHASE)->count());

        $this->buyCredits();       // a deliberate second purchase
        $this->assertSame(2 * self::PACK_NANO, $this->balance());
        $this->assertBalanceMatchesLedger();
    }

    public function test_buying_credits_requires_login_and_a_valid_key(): void
    {
        $this->post('/credits', ['idempotency_key' => (string) Str::uuid()])->assertRedirect(route('login'));
        $this->actingAs($this->user)->post('/credits', ['idempotency_key' => 'nope'])->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, $this->balance());
    }

    public function test_submitting_reserves_the_maximum_estimate(): void
    {
        $this->buyCredits();

        $generation = $this->submit();

        $this->assertSame(self::PACK_NANO - self::MAX_ESTIMATE_NANO, $this->balance());
        $this->assertSame(-self::MAX_ESTIMATE_NANO, CreditEntry::where('generation_id', $generation->id)->sole()->amount_nano);
        $this->assertBalanceMatchesLedger();
    }

    public function test_success_nets_exactly_the_actual_charge(): void
    {
        $this->buyCredits();
        $generation = $this->submit();

        app(ApplyProviderSnapshot::class)->apply($generation, $this->succeededSnapshot());

        $this->assertSame(self::PACK_NANO - self::DEFAULT_CHARGE_NANO, $this->balance());
        $this->assertSame(
            [CreditEntry::RESERVE, CreditEntry::RELEASE, CreditEntry::CHARGE],
            CreditEntry::where('generation_id', $generation->id)->orderBy('id')->pluck('type')->all(),
        );
        $this->assertBalanceMatchesLedger();
    }

    /** The heart of "never twice": the same success delivered again and again charges once. */
    public function test_a_repeated_succeeded_snapshot_does_not_charge_again(): void
    {
        $this->buyCredits();
        $generation = $this->submit();
        $snapshot = $this->succeededSnapshot(['id' => 'gen_once']);

        app(ApplyProviderSnapshot::class)->apply($generation, $snapshot);
        app(ApplyProviderSnapshot::class)->apply($generation, $snapshot);
        $this->deliverWebhook($this->event('generation.succeeded', $snapshot))->assertOk();

        $this->assertSame(1, CreditEntry::where('type', CreditEntry::CHARGE)->count());
        $this->assertSame(self::PACK_NANO - self::DEFAULT_CHARGE_NANO, $this->balance());
        $this->assertBalanceMatchesLedger();
    }

    /** Even if the forward-only guard were bypassed, the ledger's unique index refuses a second charge. */
    public function test_the_ledger_itself_refuses_a_second_charge_for_the_same_generation(): void
    {
        $generation = Generation::factory()->for($this->user)->create();
        $ledger = app(CreditLedger::class);

        $ledger->charge($generation, 1_000);
        $ledger->charge($generation, 1_000);

        $this->assertSame(-1_000, $this->balance());
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_a_failed_generation_gives_the_whole_reserve_back(): void
    {
        $this->buyCredits();
        $generation = $this->submit();

        app(ApplyProviderSnapshot::class)->apply($generation, $this->failedSnapshot());
        app(ApplyProviderSnapshot::class)->apply($generation, $this->failedSnapshot());

        $this->assertSame(self::PACK_NANO, $this->balance());
        $this->assertSame(0, CreditEntry::where('type', CreditEntry::CHARGE)->count());
        $this->assertBalanceMatchesLedger();
    }

    public function test_a_rejected_submission_gives_the_reserve_back(): void
    {
        $this->buyCredits();
        $generation = $this->submit();

        app(ApplyProviderSnapshot::class)->failWithoutProvider($generation, 'content_policy_violation', SubmitGeneration::MESSAGE_POLICY);

        $this->assertSame(self::PACK_NANO, $this->balance());
        $this->assertBalanceMatchesLedger();
    }

    /** Two submits that together exceed the balance cannot both pass: the second sees the reserve of the first. */
    public function test_the_reserve_blocks_overspending_with_parallel_generations(): void
    {
        $ledger = app(CreditLedger::class);
        $ledger->purchase($this->user, self::MAX_ESTIMATE_NANO + 1, (string) Str::uuid());   // enough for one, not for two

        $this->submit();

        $this->actingAs($this->user)->post('/generations', [
            'uuid' => (string) Str::uuid(), 'prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium',
        ])->assertSessionHasErrors('balance');

        $this->assertSame(1, Generation::count());
        $this->assertSame(1, $this->balance());
        $this->assertBalanceMatchesLedger();
    }

    public function test_a_balance_exactly_equal_to_the_maximum_estimate_is_enough(): void
    {
        app(CreditLedger::class)->purchase($this->user, self::MAX_ESTIMATE_NANO, (string) Str::uuid());

        $this->submit();

        $this->assertSame(0, $this->balance());
    }

    /**
     * Known corner (documented in the README): we gave up after timeouts and released the hold,
     * then Pixelforge reported that it did run. The real cost is still charged, even below zero,
     * because the books must match what Pixelforge billed.
     */
    public function test_a_late_claimed_generation_is_still_charged(): void
    {
        app(CreditLedger::class)->purchase($this->user, self::MAX_ESTIMATE_NANO, (string) Str::uuid());
        $generation = $this->submit();
        $snapshots = app(ApplyProviderSnapshot::class);

        $snapshots->failWithoutProvider($generation, 'submission_unconfirmed', SubmitGeneration::MESSAGE_UNCONFIRMED, nothingWasCreated: false);
        $this->assertSame(self::MAX_ESTIMATE_NANO, $this->balance());

        $row = $snapshots->apply($generation, $this->succeededSnapshot());

        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(self::MAX_ESTIMATE_NANO - self::DEFAULT_CHARGE_NANO, $this->balance());
        $this->assertBalanceMatchesLedger();
    }

    public function test_the_seeder_gives_the_demo_user_ten_dollars_once(): void
    {
        config(['demo.user.email' => 'demo@example.com', 'demo.user.password' => 'secret-for-tests',
            'demo.admin.email' => 'admin@example.com', 'demo.admin.password' => 'secret-for-tests']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, User::whereIn('email', ['demo@example.com', 'admin@example.com'])->count());
        $this->assertSame(10_000_000_000, User::where('email', 'demo@example.com')->sole()->balance_nano);
        $this->assertSame(0, User::where('email', 'admin@example.com')->sole()->balance_nano);
        $this->assertTrue(User::where('email', 'admin@example.com')->sole()->is_admin);
        $this->assertFalse(User::where('email', 'demo@example.com')->sole()->is_admin);
    }

    public function test_the_seeder_skips_accounts_without_a_password(): void
    {
        config(['demo.user.email' => 'demo@example.com', 'demo.user.password' => null,
            'demo.admin.email' => null, 'demo.admin.password' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());   // only the user from setUp
    }
}
