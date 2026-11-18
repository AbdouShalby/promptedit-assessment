<?php

namespace Database\Factories;

use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'a red fox',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'markup_bps' => 2500,
            'estimated_min_charge_nano' => 58_137_500,
            'estimated_max_charge_nano' => 86_275_000,
        ];
    }

    /** Accepted by Pixelforge and linked to a provider generation. */
    public function accepted(GenerationStatus $status = GenerationStatus::Queued): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'provider_id' => 'gen_'.Str::random(24),
            'last_checked_at' => now(),
        ]);
    }
}
