<?php

namespace App\Models;

use App\Enums\GenerationTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paid metadata generation for a work, with the full answer (App\Services\Metadata\AssetMetadataService).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $asset_id Null once the work is deleted
 * @property GenerationTrigger $trigger
 * @property string $model provider:model
 * @property array{title: string, description: string, keywords: list<string>, categories: array<string, list<string>>, looks_editorial: bool, fixes: list<string>} $result
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property string|null $cost_usd
 * @property int $duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssetGeneration extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'trigger',
        'model',
        'result',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'cost_usd',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => GenerationTrigger::class,
            'result' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function scopeThisMonth(Builder $query): void
    {
        $query->where('created_at', '>=', now()->startOfMonth());
    }
}
