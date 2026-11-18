<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** The form, the estimate endpoint and the history page. */
class GenerationPageTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();   // page tests must not depend on a frontend build
        $this->user = User::factory()->create();
        $this->fakePricing();
        Queue::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'prompt' => 'a red fox',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $overrides);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/generations')->assertRedirect(route('login'));
        $this->post('/generations', $this->form())->assertRedirect(route('login'));
        $this->postJson('/generations/estimate', $this->form())->assertUnauthorized();
    }

    public function test_a_newly_registered_unverified_user_can_use_the_page(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/generations')->assertOk();
    }

    public function test_submitting_creates_one_pending_row_with_its_estimate_and_queues_one_job(): void
    {
        $form = $this->form(['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'http://example.com/b.png']]);

        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/generations');

        $generation = Generation::sole();
        $this->assertSame($form['uuid'], $generation->uuid);
        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame(2500, $generation->markup_bps);
        $this->assertSame(357_781_250, $generation->estimated_min_charge_nano);
        $this->assertSame(467_343_750, $generation->estimated_max_charge_nano);   // the worked example x 1.25
        $this->assertSame($form['reference_images'], $generation->reference_images);

        Queue::assertPushed(SubmitGeneration::class, 1);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);
    }

    public function test_the_same_form_submitted_twice_creates_one_generation(): void
    {
        $form = $this->form();

        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/generations');
        $this->actingAs($this->user)->post('/generations', $form)->assertRedirect('/generations');

        $this->assertSame(1, Generation::count());
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_another_users_request_id_cannot_be_reused(): void
    {
        $form = $this->form();
        $this->actingAs($this->user)->post('/generations', $form);

        $this->actingAs(User::factory()->create())->post('/generations', $form)->assertSessionHasErrors('uuid');

        $this->assertSame(1, Generation::count());
    }

    public function test_input_is_validated_like_the_pixelforge_api(): void
    {
        $this->actingAs($this->user);

        $this->post('/generations', $this->form(['prompt' => str_repeat('a', 4001)]))->assertSessionHasErrors('prompt');
        $this->post('/generations', $this->form(['prompt' => '   ']))->assertSessionHasErrors('prompt');
        $this->post('/generations', $this->form(['size' => '512x512']))->assertSessionHasErrors('size');
        $this->post('/generations', $this->form(['quality' => 'ultra']))->assertSessionHasErrors('quality');
        $this->post('/generations', $this->form(['reference_images' => ['ftp://example.com/a.png']]))->assertSessionHasErrors('reference_images.0');
        $this->post('/generations', $this->form(['reference_images' => ['not a url']]))->assertSessionHasErrors('reference_images.0');
        $this->post('/generations', $this->form(['reference_images' => array_fill(0, 5, 'https://example.com/a.png')]))->assertSessionHasErrors('reference_images');
        $this->post('/generations', $this->form(['uuid' => 'nope']))->assertSessionHasErrors('uuid');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_a_prompt_of_exactly_4000_characters_and_4_reference_images_is_accepted(): void
    {
        $form = $this->form(['prompt' => str_repeat('ق', 4000), 'reference_images' => array_fill(0, 4, 'https://example.com/a.png')]);

        $this->actingAs($this->user)->post('/generations', $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Generation::count());
    }

    public function test_a_user_cannot_have_more_than_five_generations_in_flight(): void
    {
        Generation::factory()->count(5)->for($this->user)->create();

        $this->actingAs($this->user)->post('/generations', $this->form())->assertSessionHasErrors('limit');

        $this->assertSame(5, Generation::count());
    }

    public function test_submitting_is_refused_when_pricing_is_unavailable(): void
    {
        $this->resetHttpFakes();
        Http::fake([self::API.'/pricing' => Http::response($this->errorBody('overloaded'), 503)]);

        $this->actingAs($this->user)->post('/generations', $this->form())->assertSessionHasErrors('pricing');

        $this->assertSame(0, Generation::count());
    }

    public function test_the_estimate_endpoint_returns_formatted_strings(): void
    {
        $form = $this->form(['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']]);

        $this->actingAs($this->user)->postJson('/generations/estimate', $form)
            ->assertOk()
            ->assertExactJson([
                'min' => '$0.3578',
                'max' => '$0.4674',
                'min_exact' => '0.357781250',
                'max_exact' => '0.467343750',
            ]);
    }

    public function test_the_estimate_endpoint_validates_its_input(): void
    {
        $this->actingAs($this->user)->postJson('/generations/estimate', $this->form(['prompt' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt');
    }

    public function test_the_page_shows_only_the_users_own_generations_with_money_as_strings(): void
    {
        $mine = Generation::factory()->for($this->user)->accepted(GenerationStatus::Succeeded)->create([
            'charge_nano' => 67_518_750,
            'provider_cost_nano' => 54_015_000,
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
        ]);
        Generation::factory()->create();   // someone else's

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.uuid', $mine->uuid)
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.status_label', 'Succeeded')
            ->where('generations.data.0.estimated_min', '$0.0582')
            ->where('generations.data.0.estimated_max', '$0.0863')
            ->where('generations.data.0.charged', '$0.0676')
            ->where('generations.data.0.charged_exact', '0.067518750')
            ->where('generations.data.0.image_url', 'https://pixelforge.promptedit.dev/images/example.png')
            ->where('generations.data.0.image_expired', false)
            ->missing('generations.data.0.provider_cost_nano')
            ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('options.qualities', ['low', 'medium', 'high'])
        );
    }

    public function test_a_pending_generation_is_shown_as_queued_and_an_expired_image_is_hidden(): void
    {
        Generation::factory()->for($this->user)->accepted(GenerationStatus::Succeeded)->create([
            'charge_nano' => 67_518_750,
            'image_url' => 'https://pixelforge.promptedit.dev/images/old.png',
            'image_url_expires_at' => now()->subMinute(),
        ]);
        Generation::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('generations.data.0.status', 'pending')
            ->where('generations.data.0.status_label', 'Queued')
            ->where('generations.data.0.charged', null)
            ->where('generations.data.1.image_url', null)
            ->where('generations.data.1.image_expired', true)
        );
    }

    public function test_the_page_still_loads_when_pricing_is_unavailable(): void
    {
        $this->resetHttpFakes();
        Http::fake([self::API.'/pricing' => Http::response($this->errorBody('overloaded'), 503)]);

        $this->actingAs($this->user)->get('/generations')->assertInertia(fn (AssertableInertia $page) => $page->where('options', null));
    }

    public function test_home_and_dashboard_lead_to_the_generations_page(): void
    {
        $this->get('/')->assertRedirect('/generations');
        $this->actingAs($this->user)->get('/dashboard')->assertRedirect('/generations');
    }
}
