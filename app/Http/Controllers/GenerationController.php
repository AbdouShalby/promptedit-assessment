<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Generations\GenerationStatus;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Nano;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /** The single page: the generate form plus the user's own history. */
    public function index(Request $request, PixelforgeClient $pixelforge): Response
    {
        $generations = $request->user()->generations()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Generation $generation) => $this->present($generation));

        return Inertia::render('generations/Index', [
            'generations' => $generations,
            'options' => $this->formOptions($pixelforge),
            // Shown rounded down, so the screen never promises credit the user does not have.
            'balance' => Nano::formatBalance($request->user()->balance_nano),
            'credit_pack' => Nano::formatBalance(config('pixelforge.credit_pack_nano')),
            'limits' => [
                'prompt' => EstimateGenerationRequest::MAX_PROMPT_CHARACTERS,
                'reference_images' => EstimateGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
        ]);
    }

    public function store(StoreGenerationRequest $request, CreditLedger $credit): RedirectResponse
    {
        $user = $request->user();
        $uuid = $request->validated('uuid');

        // The same form submitted twice (double click, retry after a timeout): nothing new to do.
        if ($existing = Generation::where('uuid', $uuid)->first()) {
            return $this->alreadySubmitted($existing, $user->id);
        }

        $estimate = $request->estimate();

        try {
            $generation = DB::transaction(function () use ($request, $user, $uuid, $estimate, $credit) {
                // Lock the user row: two submits at the same moment cannot both spend the same credit.
                $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('balance_nano');

                $this->ensureUnderInFlightLimit($user);
                $this->ensureCanAfford($balance, $estimate->maxChargeNano);

                $generation = $user->generations()->create([
                    'uuid' => $uuid,
                    'status' => GenerationStatus::Pending,
                    'prompt' => $request->validated('prompt'),
                    'size' => $request->validated('size'),
                    'quality' => $request->validated('quality'),
                    'reference_images' => array_values($request->validated('reference_images', [])),
                    'markup_bps' => config('pixelforge.markup_bps'),
                    'estimated_min_charge_nano' => $estimate->minChargeNano,
                    'estimated_max_charge_nano' => $estimate->maxChargeNano,
                ]);

                // Hold the most it can cost; settled to the actual cost when it finishes.
                $credit->reserve($generation);

                return $generation;
            });
        } catch (UniqueConstraintViolationException) {
            return to_route('generations.index');   // a concurrent request with the same uuid won the race
        }

        // The row is committed before Pixelforge is called, so a webhook that
        // arrives before the API response can still find it.
        SubmitGeneration::dispatch($generation->id);

        return to_route('generations.index');
    }

    private function ensureUnderInFlightLimit(User $user): void
    {
        $limit = config('pixelforge.max_in_flight_per_user');

        if ($user->generations()->inFlight()->count() >= $limit) {
            throw ValidationException::withMessages([
                'limit' => "You already have {$limit} generations in progress. Wait for one to finish.",
            ]);
        }
    }

    /** Blocks on the maximum estimate, which is stricter than the final cost, so a balance can never go negative here. */
    private function ensureCanAfford(int $balanceNano, int $maxChargeNano): void
    {
        if ($balanceNano < $maxChargeNano) {
            throw ValidationException::withMessages([
                'balance' => 'Not enough credit: this generation can cost up to '.Nano::formatCost($maxChargeNano)
                    .' and you have '.Nano::formatBalance($balanceNano).'. Buy credits to continue.',
            ]);
        }
    }

    private function alreadySubmitted(Generation $existing, int $userId): RedirectResponse
    {
        if ($existing->user_id !== $userId) {
            throw ValidationException::withMessages(['uuid' => 'This request id is not valid. Reload the page and try again.']);
        }

        return to_route('generations.index');
    }

    /** @return array{sizes: list<string>, qualities: list<string>}|null null when pricing is unavailable */
    private function formOptions(PixelforgeClient $pixelforge): ?array
    {
        try {
            $pricing = $pixelforge->pricing();
        } catch (PixelforgeException) {
            return null;
        }

        return ['sizes' => $pricing->sizes(), 'qualities' => $pricing->qualities()];
    }

    /**
     * What the browser gets. Money leaves the server as ready-made strings:
     * the frontend never does arithmetic on it.
     *
     * @return array<string, mixed>
     */
    private function present(Generation $generation): array
    {
        $imageExpired = $generation->image_url_expires_at?->isPast() ?? false;

        return [
            'uuid' => $generation->uuid,
            'status' => $generation->status->value,
            'status_label' => $generation->status->label(),
            'waiting_for_slot' => $generation->status === GenerationStatus::Pending,
            'is_final' => $generation->status->isFinal(),
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_image_count' => count($generation->reference_images),
            'estimated_min' => Nano::formatCost($generation->estimated_min_charge_nano),
            'estimated_max' => Nano::formatCost($generation->estimated_max_charge_nano),
            'estimated_max_exact' => Nano::toDecimal($generation->estimated_max_charge_nano),
            // null = not known yet (in flight), or never confirmed by Pixelforge.
            'charged' => $generation->charge_nano === null ? null : Nano::formatCost($generation->charge_nano),
            'charged_exact' => $generation->charge_nano === null ? null : Nano::toDecimal($generation->charge_nano),
            'image_url' => $imageExpired ? null : $generation->image_url,
            'image_expired' => $imageExpired,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at->toIso8601String(),
        ];
    }
}
