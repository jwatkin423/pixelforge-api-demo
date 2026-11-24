<?php

namespace App\Http\Requests\Generations;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationCostRequest extends FormRequest
{
    /**
     * Lenient on purpose: the estimate updates while the user is still typing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }
}
