<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'estimated_min_charge_nanos' => 57_502_500,
            'estimated_max_charge_nanos' => 86_265_000,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => 'gen_'.Str::random(20),
            'submitted_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * A finished generation billed at the given Pixelforge cost (default: example from the docs).
     */
    public function succeeded(int $providerCostNanos = 46_125_000, ?int $chargedNanos = null): static
    {
        return $this->processing()->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost_nanos' => $providerCostNanos,
            'charged_nanos' => $chargedNanos ?? intdiv($providerCostNanos * 125 + 99, 100),
            'image_url' => 'https://example.com/image.png',
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->processing()->state(fn (array $attributes): array => [
            'status' => GenerationStatus::Failed,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }
}
