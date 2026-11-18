<?php

namespace Tests\Feature\Admin;

use App\Generations\ApplyProviderSnapshot;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** Provider cost per UTC day must equal what Pixelforge reports in GET /v1/usage. */
class ReportTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-11-20 12:00:00');
        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);
    }

    /** A succeeded generation completed at the given time, with the default usage (cost 0.054015000). */
    private function completed(User $user, string $completedAt): Generation
    {
        return app(ApplyProviderSnapshot::class)->apply(
            Generation::factory()->for($user)->create(),
            $this->succeededSnapshot(['completed_at' => $completedAt]),
        );
    }

    /**
     * A GET /v1/usage response shaped like the docs.
     *
     * @param  array<string, string>  $costByDate
     */
    private function fakeUsage(array $costByDate): void
    {
        $data = [];

        foreach ($costByDate as $date => $cost) {
            $data[] = ['date' => $date, 'generations' => 1, 'input_text_tokens' => 0, 'input_image_tokens' => 0,
                'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost];
        }

        Http::fake([self::API.'/usage*' => Http::response(['object' => 'usage', 'data' => $data, 'total' => ['cost_usd' => '0.000000000']])]);
    }

    public function test_only_admins_can_open_the_report(): void
    {
        $this->get('/admin/report')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_it_aggregates_per_utc_day_and_user_and_matches_usage(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        $this->completed($alice, '2026-11-18T10:00:00Z');
        $this->completed($alice, '2026-11-18T11:00:00Z');
        $this->completed($bob, '2026-11-18T12:00:00Z');
        $this->completed($bob, '2026-11-19T09:00:00Z');

        // 0.054015000 each: three on the 18th, one on the 19th. Zero days are included by the API.
        $this->fakeUsage(['2026-11-17' => '0.000000000', '2026-11-18' => '0.162045000', '2026-11-19' => '0.054015000']);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->where('filters', ['start_date' => '2026-10-22', 'end_date' => '2026-11-20'])
            ->where('error', null)
            ->has('days', 2)
            // newest day first
            ->where('days.0.date', '2026-11-19')
            ->where('days.0.provider_cost', '0.054015000')
            ->where('days.0.matches_usage', true)
            ->where('days.1.date', '2026-11-18')
            ->where('days.1.generations', 3)
            ->where('days.1.charged', '0.202556250')          // 3 x 0.067518750
            ->where('days.1.provider_cost', '0.162045000')
            ->where('days.1.profit', '0.040511250')
            ->where('days.1.usage_cost', '0.162045000')
            ->where('days.1.matches_usage', true)
            ->where('days.1.difference', null)
            ->has('days.1.rows', 2)
            ->where('days.1.rows.0', ['user' => 'alice@example.com', 'generations' => 2, 'charged' => '0.135037500', 'provider_cost' => '0.108030000', 'profit' => '0.027007500'])
            ->where('days.1.rows.1.user', 'bob@example.com')
            ->where('total.generations', 4)
            ->where('total.provider_cost', '0.216060000')
            ->where('total.usage_cost', '0.216060000')
            ->where('total.matches_usage', true)
        );

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_date=2026-10-22&end_date=2026-11-20'));
    }

    /** Created at 23:59, completed at 00:01 UTC: Pixelforge bills it on the second day, and so do we. */
    public function test_a_generation_is_reported_on_the_utc_day_it_completed(): void
    {
        $user = User::factory()->create();
        app(ApplyProviderSnapshot::class)->apply(
            Generation::factory()->for($user)->create(['created_at' => '2026-11-18 23:59:00']),
            $this->succeededSnapshot(['created_at' => '2026-11-18T23:59:00Z', 'completed_at' => '2026-11-19T00:01:00Z']),
        );
        $this->fakeUsage(['2026-11-18' => '0.000000000', '2026-11-19' => '0.054015000']);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 1)
            ->where('days.0.date', '2026-11-19')
            ->where('days.0.matches_usage', true)
        );
    }

    public function test_a_difference_of_one_nanodollar_is_shown_as_a_mismatch(): void
    {
        $this->completed(User::factory()->create(), '2026-11-18T10:00:00Z');
        $this->fakeUsage(['2026-11-18' => '0.054015001']);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.matches_usage', false)
            ->where('days.0.difference', '-0.000000001')   // ours minus theirs
            ->where('total.matches_usage', false)
        );
    }

    /** Something was billed on the key that our database does not know: the day must still appear. */
    public function test_a_day_billed_only_by_pixelforge_is_shown_as_a_mismatch(): void
    {
        $this->fakeUsage(['2026-11-18' => '0.054015000']);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 1)
            ->where('days.0.date', '2026-11-18')
            ->where('days.0.provider_cost', '0.000000000')
            ->where('days.0.matches_usage', false)
            ->where('days.0.difference', '-0.054015000')
        );
    }

    public function test_failed_generations_contribute_nothing(): void
    {
        $user = User::factory()->create();
        app(ApplyProviderSnapshot::class)->apply(Generation::factory()->for($user)->create(), $this->failedSnapshot());
        $this->fakeUsage(['2026-11-18' => '0.000000000']);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 0)
            ->where('total.provider_cost', '0.000000000')
            ->where('total.matches_usage', true)
        );
    }

    public function test_the_report_still_renders_when_usage_cannot_be_loaded(): void
    {
        $this->completed(User::factory()->create(), '2026-11-18T10:00:00Z');
        Http::fake([self::API.'/usage*' => Http::response($this->errorBody('overloaded'), 503)]);

        $this->actingAs($this->admin)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.provider_cost', '0.054015000')
            ->where('days.0.usage_cost', null)
            ->where('days.0.matches_usage', null)
            ->where('error', fn (string $error) => str_contains($error, 'overloaded'))
        );
    }

    public function test_the_range_is_validated_and_limited_to_92_days(): void
    {
        Http::fake();
        $this->actingAs($this->admin);

        $this->get('/admin/report?start_date=2026-08-01&end_date=2026-11-20')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 0)
            ->where('error', fn (string $error) => str_contains($error, '92 days'))
        );
        $this->get('/admin/report?start_date=nonsense')->assertSessionHasErrors('start_date');
        $this->get('/admin/report?start_date=2026-11-20&end_date=2026-11-01')->assertSessionHasErrors('end_date');

        Http::assertNothingSent();
    }

    public function test_exactly_92_days_is_allowed(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get('/admin/report?start_date=2026-08-21&end_date=2026-11-20')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('error', null));
    }
}
