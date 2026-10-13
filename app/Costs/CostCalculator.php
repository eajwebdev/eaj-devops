<?php

namespace App\Costs;

use App\Models\AiModel;

/** Prices are configured in USD per one million tokens. */
class CostCalculator
{
    /**
     * @return array{input: float, cached_input: float, output: float, total: float, pricing: array<string, float>}|null
     */
    public function calculate(AiModel $model, int $input, int $cachedInput, int $output): ?array
    {
        $cached = min(max(0, $cachedInput), max(0, $input));
        $uncached = max(0, $input - $cached);

        if (($uncached > 0 && $model->input_cost === null)
            || ($cached > 0 && $model->cached_input_cost === null)
            || ($output > 0 && $model->output_cost === null)) {
            return null;
        }

        $inputRate = (float) ($model->input_cost ?? 0);
        $cachedRate = (float) ($model->cached_input_cost ?? 0);
        $outputRate = (float) ($model->output_cost ?? 0);
        $inputCost = round($uncached * $inputRate / 1_000_000, 6);
        $cachedCost = round($cached * $cachedRate / 1_000_000, 6);
        $outputCost = round(max(0, $output) * $outputRate / 1_000_000, 6);

        return [
            'input' => $inputCost,
            'cached_input' => $cachedCost,
            'output' => $outputCost,
            'total' => round($inputCost + $cachedCost + $outputCost, 6),
            'pricing' => [
                'input_per_million' => $inputRate,
                'cached_input_per_million' => $cachedRate,
                'output_per_million' => $outputRate,
            ],
        ];
    }
}
