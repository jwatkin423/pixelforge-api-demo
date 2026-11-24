<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A received Pixelforge event. The unique event_id is how duplicate deliveries are dropped.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property int|null $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'type',
        'pixelforge_generation_id',
        'delivery_attempt',
        'payload',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
        ];
    }
}
