<?php

namespace App\Models;

use Database\Factories\MuteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A per-user mute: the muted participant's messages stay in the database but
 * are hidden from the muting user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $muted_user_id
 * @property Carbon|null $muted_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'muted_user_id', 'muted_until'])]
class Mute extends Model
{
    /** @use HasFactory<MuteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'muted_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mutedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_user_id');
    }
}
