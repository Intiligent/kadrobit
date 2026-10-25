<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A setting the user changed from its default; the registry is App\Enums\UserSettingKey.
 * Read and written through User::setting() and User::setSetting(), not directly.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key App\Enums\UserSettingKey value
 * @property mixed $value Decoded JSON: bool, int, string or array
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UserSetting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
