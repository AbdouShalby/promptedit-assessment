<?php

namespace App\Models;

use App\Generations\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Generations that hold one of Pixelforge's concurrent slots. */
    public function scopeHoldingProviderSlot(Builder $query): void
    {
        $query->whereNotNull('provider_id')
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

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
