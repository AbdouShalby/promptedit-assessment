<?php

namespace App\Http\Requests;

use App\Billing\Estimate;
use App\Billing\Estimator;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\Pricing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The generation parameters, validated the way the Pixelforge API validates
 * them, so a request that passes here is not rejected there.
 */
class EstimateGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_CHARACTERS = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    private ?Pricing $pricing = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = $this->pricing();

        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            // The allowed sizes and qualities are whatever the price list knows how to price.
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'max:2000', 'url:http,https'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be a full http(s) URL.',
            'reference_images.*.required' => 'Remove empty reference image fields or fill them in.',
            'reference_images.max' => 'You can add at most '.self::MAX_REFERENCE_IMAGES.' reference images.',
        ];
    }

    /** Estimate for the validated input: the same strings that will be stored and sent. */
    public function estimate(): Estimate
    {
        return Estimator::estimate(
            prompt: $this->validated('prompt'),
            size: $this->validated('size'),
            quality: $this->validated('quality'),
            referenceImageCount: count($this->validated('reference_images', [])),
            pricing: $this->pricing(),
            markupBps: config('pixelforge.markup_bps'),
        );
    }

    private function pricing(): Pricing
    {
        try {
            return $this->pricing ??= app(PixelforgeClient::class)->pricing();
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'pricing' => 'Pricing is unavailable right now, so we cannot quote a price. Please try again in a minute.',
            ]);
        }
    }
}
