<?php

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Support\Nano;
use Illuminate\Http\JsonResponse;

/** Live price estimate for the form. Computed on the server so there is one implementation of the maths. */
class EstimateController extends Controller
{
    public function __invoke(EstimateGenerationRequest $request): JsonResponse
    {
        $estimate = $request->estimate();

        return response()->json([
            'min' => Nano::formatCost($estimate->minChargeNano),
            'max' => Nano::formatCost($estimate->maxChargeNano),
            'min_exact' => Nano::toDecimal($estimate->minChargeNano),
            'max_exact' => Nano::toDecimal($estimate->maxChargeNano),
        ]);
    }
}
