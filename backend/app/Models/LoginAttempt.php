<?php

namespace App\Models;

use Database\Factories\LoginAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail of authentication attempts, used for rate limiting and abuse
 * detection.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property string $ip_address
 * @property string|null $device_hash
 * @property string $reason
 * @property bool $succeeded
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'email', 'ip_address', 'device_hash', 'reason', 'succeeded'])]
class LoginAttempt extends Model
{
    /** @use HasFactory<LoginAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
