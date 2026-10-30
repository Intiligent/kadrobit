<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The user's own label for works, for organising the library; never sent to marketplaces.
 * Names are unique per user ignoring case. Created through `$user->tags()`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name As first typed: "Batumi" stays capitalised
 */
class Tag extends Model
{
    /** Longest name. */
    public const NAME_MAX = 50;

    /** Letters of any language, digits, spaces, hyphens and underscores. */
    public const NAME_PATTERN = '/^[\p{L}\p{N} _-]+$/u';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class);
    }
}
