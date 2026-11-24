<?php

namespace App\Http\Requests\Generations;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Mirrors Pixelforge's own limits so users get errors here instead of a failed job.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.distinct' => 'This reference image is already in the list.',
        ];
    }

    public function size(): GenerationSize
    {
        return GenerationSize::from($this->string('size')->toString());
    }

    public function quality(): GenerationQuality
    {
        return GenerationQuality::from($this->string('quality')->toString());
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        return array_values(array_filter((array) $this->input('reference_images', [])));
    }
}
