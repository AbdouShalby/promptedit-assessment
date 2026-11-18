<?php

namespace App\Models;

use App\Generations\GenerationStatus;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image generation requested by a user. All *_nano columns are integer nanodollars.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $markup_bps
 * @property int $estimated_min_charge_nano
 * @property int $estimated_max_charge_nano
 * @property array<string, string>|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nano
 * @property int|null $charge_nano
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property int $rate_limit_attempts
 * @property bool $submit_outcome_unknown
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $billing_date
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'submit_outcome_unknown' => 'boolean',
            'image_url_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'billing_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generations that hold one of Pixelforge's concurrent slots.
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldingProviderSlot(Builder $query): void
    {
        $query->whereNotNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /** @param  Builder<self>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    /**
     * The body for POST /v1/generations. Built only from stored columns so every
     * retry sends identical params (a different body with the same
     * Idempotency-Key is a 409). Unknown params are rejected by the API.
     *
     * @return array<string, mixed>
     */
    public function providerPayload(): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => ['local_id' => $this->uuid],
        ];

        if ($this->reference_images !== []) {
            $payload['reference_images'] = $this->reference_images;
        }

        return $payload;
    }
}
